<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\App\Services\UnlockService;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Support\Arr;

/**
 * Re-signs a media's playback source on demand, so a cached page whose provider token has
 * died can heal instead of stalling.
 *
 * Publicly reachable by design — the viewer is usually a guest — so the signed grant is
 * the whole authorization story.
 */
class PlaybackController extends Controller
{
    /**
     * An allowlist, not the whole settings array.
     *
     * @return string[]
     */
    protected function playbackFields()
    {
        // Tokens beyond `src` are required: without them the manifest loads and every
        // segment 403s. Subtrees are pruned to playback leaves below.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return (array) apply_filters('fluent_player/playback_source_fields', [
            'src',
            'posterSrc',
            'thumbnails',
            'subtitles',
            'signed_url_expires_at',
            'bunny_token_query',
            'bunny_storage',
            'bunny',
            'stream',
            'drm',
        ]);
    }

    /**
     * Keeps a widened field list from turning into an account-config disclosure.
     *
     * @param string $field
     * @param mixed  $value
     * @return mixed
     */
    protected static function prunePlaybackSubtree($field, $value)
    {
        $allowedLeaves = [
            'bunny'         => ['mp4_urls'],
            'bunny_storage' => ['url', 'streamingUrl'],
            'stream'        => ['playback_token'],
            'drm'           => ['mux_token'],
        ];

        if (!isset($allowedLeaves[$field]) || !is_array($value)) {
            return $value;
        }

        return array_intersect_key($value, array_flip($allowedLeaves[$field]));
    }

    /**
     * A stored copy of this response would make the heal path its own source of stale
     * tokens. LiteSpeed caches REST routes by default, hence its action too.
     *
     * @return void
     */
    protected static function forbidCaching()
    {
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Third-party hook name
        do_action('litespeed_control_set_nocache', 'fluent-player signed playback source');
    }

    /**
     * Brake on repeated FAILED grant validations, mirroring the sibling nopriv route.
     *
     * @return bool
     */
    protected static function hitFailureLimit()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only throttle key
        $ip = sanitize_text_field(wp_unslash(Arr::get($_SERVER, 'REMOTE_ADDR', '')));

        // Behind a proxy that does not restore the client address every visitor shares one
        // bucket, so a site can supply the real one.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $ip = (string) apply_filters('fluent_player/playback_source_rate_key', $ip);
        if ($ip === '') {
            return false;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $max = (int) apply_filters('fluent_player/playback_source_rate_limit', 30);
        if ($max < 0) {
            return false;
        }

        return Helper::hitRateLimit('flp_pb_' . md5($ip), $max, 'flp_playback', MINUTE_IN_SECONDS);
    }

    /**
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function source(Request $request)
    {
        self::forbidCaching();

        $rawId    = $request->get('media_id', 0);
        $rawGrant = $request->get('grant', '');

        // Array-typed params would otherwise hit a string cast and warn into the response.
        $mediaId = is_scalar($rawId) ? absint($rawId) : 0;
        $grant   = is_scalar($rawGrant) ? sanitize_text_field((string) $rawGrant) : '';

        if (!$mediaId || !UnlockService::validatePlaybackGrant($mediaId, $grant)) {
            // Failures only: counting successes would let anyone exhaust the budget for a
            // whole shared IP — the NAT and reverse-proxy case this runs behind.
            if (self::hitFailureLimit()) {
                return $this->sendError([
                    'message' => __('Too many requests. Please try again shortly.', 'fluent-player'),
                ], 429);
            }

            // Identical to the not-found response below, so this is not an existence
            // oracle for unpublished media.
            return $this->sendError([
                'message' => __('This media is not available.', 'fluent-player'),
            ], 403);
        }

        // No nonce (a cached one is stale), so WordPress ran us as user 0. Restore the cookie
        // user so the gates below see the real caller; the grant is still required.
        $cookieUserId = wp_validate_auth_cookie('', 'logged_in');
        if ($cookieUserId) {
            wp_set_current_user($cookieUserId);
        }

        $post = get_post($mediaId);

        if (!$post || $post->post_type !== Media::$postType) {
            return $this->sendError([
                'message' => __('This media is not available.', 'fluent-player'),
            ], 403);
        }

        // The grant is a bearer token, so every gate the render path applies is re-applied
        // here; otherwise access rules become transferable.
        if (!in_array($post->post_status, ['publish', 'private'], true)) {
            return $this->sendError([
                'message' => __('This media is not available.', 'fluent-player'),
            ], 403);
        }

        // Flipping publish → private is how an operator takes a video down, and grants
        // outlive that action.
        if ($post->post_status === 'private' && !current_user_can('read_post', $mediaId)) {
            return $this->sendError([
                'message' => __('This media is not available.', 'fluent-player'),
            ], 403);
        }

        if (post_password_required($post) && !UnlockService::cookieUnlocked($mediaId)) {
            return $this->sendError([
                'message' => __('This media is not available.', 'fluent-player'),
            ], 403);
        }

        // The visibility filter every other embed path honours via Media::findVisible().
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        if (!apply_filters('fluent_player/can_view_media', true, $post)) {
            return $this->sendError([
                'message' => __('This media is not available.', 'fluent-player'),
            ], 403);
        }

        $settings = Media::getMediaSettings($post);

        if (!is_array($settings)) {
            return $this->sendError([
                'message' => __('This media is not available.', 'fluent-player'),
            ], 403);
        }

        $settings = Media::mergePresetSettings($settings);

        $settings = apply_filters('fluent_player/player_settings', $settings);

        // Unconditional: a method_exists() guard would silently disable stripping the
        // moment the method is renamed.
        $settings = MediaService::stripSensitiveProviderConfig($settings);

        // A media moved off Bunny Storage keeps the old subtree, and the runtime prefers it.
        if (Arr::get($settings, 'provider') !== 'bunny_storage') {
            unset($settings['bunny_storage']);
        }

        $payload = [];
        foreach ($this->playbackFields() as $field) {
            $value = Arr::get($settings, $field);
            if ($value === null || $value === '') {
                continue;
            }

            $payload[$field] = self::prunePlaybackSubtree($field, $value);
        }

        return $this->sendSuccess($payload);
    }
}
