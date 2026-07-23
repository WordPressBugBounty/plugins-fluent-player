<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Integrations\FluentCrm\Identity;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Integrations\FluentCrm\BehaviorState;

abstract class AbstractBehaviorHandler
{
    const NONCE_ACTION = 'fluent_player_behavior';
    const RATE_LIMIT = 60;
    const AJAX_ACTION = '';
    const LOCK_TTL = 15;
    const ANON_COOKIE = 'flp_bhv_anon';
    const ANON_COOKIE_TTL = 21600;

    protected $app;

    public function __construct()
    {
        $this->app = App::make();
    }

    public static function register()
    {
        if (!defined('FLUENTCRM')) {
            return;
        }
        $instance = new static();
        add_action('wp_ajax_' . static::AJAX_ACTION, [$instance, 'handle']);
        add_action('wp_ajax_nopriv_' . static::AJAX_ACTION, [$instance, 'handle']);
    }

    /**
     * Per-visitor consent veto over ALL behavior reporting (automation and
     * timeline). Consent plugins hook this and return false for visitors
     * who haven't consented; default true keeps current behavior.
     */
    public static function canReport($ip = '')
    {
        return (bool) apply_filters('fluent_player/behavior_can_report', true, [
            'user_id'     => get_current_user_id(),
            'has_contact' => Identity::isCurrentUserContact(),
            'ip'          => (string) $ip,
        ]);
    }

    abstract protected function eventName();

    abstract protected function armedFor($mediaId);

    /**
     * @return array ['state' => array, 'fired' => ctx[], 'reached' => string[], 'extra' => array]
     */
    abstract protected function evaluate($mediaId, array $armed, array $state, array $identity);

    public function handle()
    {
        try {
            $response = $this->process();
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FluentPlayer Behavior Error: ' . $e->getMessage());
            }
            $response = ['error' => __('An unexpected error occurred', 'fluent-player'), 'code' => 500];
        }

        if (Arr::has($response, 'error')) {
            wp_send_json_error(['message' => $response['error']], Arr::get($response, 'code', 500));
            return;
        }

        wp_send_json_success(Arr::get($response, 'data'));
    }

    protected function process()
    {
        $nonce = sanitize_text_field($this->app->request->get('nonce', ''));
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return ['error' => __('Security check failed', 'fluent-player'), 'code' => 403];
        }

        // the nopriv nonce is a shared public value, so a same-origin check is the
        // real CSRF defense for the state-writing/emitting path
        if (!$this->isSameOrigin()) {
            return ['error' => __('Security check failed', 'fluent-player'), 'code' => 403];
        }

        $ip = $this->app->request->getIp();
        if ($this->hitRateLimit('flp_beh_rl_' . md5($ip))) {
            return ['error' => __('Too many requests', 'fluent-player'), 'code' => 429];
        }

        // after the rate limit — consent resolves the contact (DB work the limiter caps)
        if (!self::canReport($ip)) {
            return ['error' => __('Reporting is disabled for this visitor', 'fluent-player'), 'code' => 403];
        }

        $mediaId = absint($this->app->request->get('media_id'));
        if (!$mediaId) {
            return ['error' => __('Invalid media', 'fluent-player'), 'code' => 422];
        }

        $armed = $this->armedFor($mediaId);
        if (!$armed) {
            return ['data' => ['fired' => 0, 'reached' => []]];
        }

        $media = Media::findVisible($mediaId);
        if (!$media) {
            return ['error' => __('Invalid media', 'fluent-player'), 'code' => 404];
        }

        $userId = get_current_user_id();
        $contact = Identity::getCurrentContact();
        $contact = $contact ?: null;

        // mint a per-browser anon id only while unidentified; else just read it to migrate
        $anonId = $this->resolveAnonId(!($contact || $userId));

        $scope = BehaviorState::resolveScope($contact, $userId, $anonId);

        // serialize load→evaluate→save→emit per identity+media, else concurrent pings
        // both judge the same milestone fresh and double-emit; the lock-loser retries
        $lockKey = 'flp_beh_lock_' . md5($scope['type'] . ':' . $scope['id'] . ':' . $mediaId);
        $lockToken = $this->acquireLock($lockKey);
        if ($lockToken === null) {
            $existing = BehaviorState::load($scope, $mediaId, $anonId);
            return ['data' => array_merge([
                'fired'   => 0,
                'reached' => $this->reachedFromState($existing),
            ], $this->extraFromState($existing, $mediaId))];
        }

        try {
            $state = BehaviorState::load($scope, $mediaId, $anonId);

            $identity = [
                'contact' => $contact,
                'user_id' => (int) $userId,
                'email'   => $contact ? $contact->email : $this->userEmail($userId),
            ];

            $result = $this->evaluate($mediaId, $armed, $state, $identity);

            BehaviorState::save($scope, $mediaId, $result['state']);

            $emitted = 0;
            if ($contact || $userId) {
                foreach (Arr::get($result, 'fired', []) as $ctx) {
                    do_action($this->eventName(), $ctx);
                    $emitted++;
                }
            }
        } finally {
            $this->releaseLock($lockKey, $lockToken);
        }

        return ['data' => array_merge([
            'fired'   => $emitted,
            'reached' => Arr::get($result, 'reached', []),
        ], Arr::get($result, 'extra', []))];
    }

    // lock-loser payload without re-evaluating; handlers override to match their shape
    protected function reachedFromState(array $state)
    {
        return array_values((array) Arr::get($state, 'fired', []));
    }

    protected function extraFromState(array $state, $mediaId)
    {
        return [];
    }

    // Atomic mutex on the options table's unique option_name index (no object cache
    // needed). Value is "<acquiredAt>:<token>"; returns the owner token to release
    // with, or null when held — so a stolen lock can't be freed by its old owner.
    protected function acquireLock($key)
    {
        global $wpdb;
        $now = time();
        $value = $now . ':' . wp_generate_password(20, false);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic INSERT is the lock primitive
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            $value
        ));
        if ((int) $inserted === 1) {
            return $value;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- stale-lock recovery
        $held = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $key
        ));
        if ($held !== null && ($now - (int) strtok((string) $held, ':')) > self::LOCK_TTL) {
            // steal only if the row is still the exact stale value we just read
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap steal
            $stolen = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $value,
                $key,
                $held
            ));
            return $stolen ? $value : null;
        }
        return null;
    }

    protected function releaseLock($key, $token)
    {
        global $wpdb;
        // compare-and-delete: never free a lock another request has since stolen
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- release only our own mutex row
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $key,
            $token
        ));
    }

    /**
     * @return bool true when this IP is over the per-minute cap. Uses an atomic
     *   object-cache increment when a persistent cache is present; else a transient
     *   read-modify-write (looser under bursts, but low-traffic sites never hit it).
     */
    protected function hitRateLimit($key)
    {
        if (wp_using_ext_object_cache()) {
            $count = wp_cache_incr($key, 1, 'flp_beh');
            if (false === $count) {
                wp_cache_add($key, 1, 'flp_beh', 60);
                $count = 1;
            }
            return (int) $count > self::RATE_LIMIT;
        }

        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return true;
        }
        set_transient($key, $count + 1, 60);
        return false;
    }

    /**
     * Sweep mutex rows a fatal/timeout left behind (compare-and-delete only frees
     * clean exits). Hooked on the daily cleanup cron.
     */
    public static function reapStaleLocks()
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk stale-lock sweep
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d",
            $wpdb->esc_like('flp_beh_lock_') . '%',
            time() - HOUR_IN_SECONDS
        ));
    }

    /**
     * A per-browser opaque id for bucketing anonymous watch state. Sourced from a
     * first-party httpOnly cookie so two visitors behind the same NAT never share a
     * bucket. Returns '' when absent and $mint is false (identified viewers that
     * never had one) — the caller then simply has no anon bucket to migrate.
     */
    protected function resolveAnonId($mint = true)
    {
        $existing = isset($_COOKIE[self::ANON_COOKIE])
            ? sanitize_text_field(wp_unslash($_COOKIE[self::ANON_COOKIE]))
            : '';
        if (preg_match('/^[a-f0-9]{32}$/', $existing)) {
            return $existing;
        }
        if (!$mint) {
            return '';
        }

        $id = bin2hex(random_bytes(16));
        // best-effort persistence; this request uses $id regardless so it's never shared
        if (!headers_sent()) {
            setcookie(self::ANON_COOKIE, $id, time() + self::ANON_COOKIE_TTL, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true);
        }
        $_COOKIE[self::ANON_COOKIE] = $id;
        return $id;
    }

    protected function isSameOrigin()
    {
        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);

        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            $host = wp_parse_url(esc_url_raw(wp_unslash($_SERVER[$header])), PHP_URL_HOST);
            return $host && strcasecmp($host, (string) $siteHost) === 0;
        }

        // no browser-set Origin/Referer to check → fall back to the nonce
        return true;
    }

    protected function userEmail($userId)
    {
        $user = $userId ? get_userdata($userId) : null;
        return $user ? $user->user_email : '';
    }
}
