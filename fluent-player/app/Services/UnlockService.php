<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Str;

/**
 * Access-control primitives for the AJAX unlock flow: password verification,
 * stateless signed tokens, and brute-force rate limiting. Tokens are HMAC-signed
 * and keyed with the post's current post_password, so changing the password
 * invalidates every outstanding token with no server-side store.
 */
class UnlockService
{
    const DEFAULT_TTL = 7200;

    public static function tokenTtl()
    {
        return (int) apply_filters('fluent_player/unlock_token_ttl', self::DEFAULT_TTL);
    }

    public static function verifyPassword($post, $password)
    {
        if (!$post || (string) $post->post_password === '') {
            return false;
        }
        // post_password is plaintext (WP's design); compare directly, not via wp_check_password.
        return hash_equals((string) $post->post_password, (string) $password);
    }

    public static function issueToken($post)
    {
        if (!$post) {
            return '';
        }
        $payload = self::b64encode((string) wp_json_encode([
            'id'  => (int) $post->ID,
            'exp' => time() + self::tokenTtl(),
        ]));
        return $payload . '.' . hash_hmac('sha256', $payload, self::secret($post));
    }

    public static function sendUnlockCookie($post)
    {
        $token = self::issueToken($post);
        if ($token === '') {
            return;
        }
        setcookie('fp_unlock_' . (int) $post->ID, $token, [
            'expires'  => time() + self::tokenTtl(),
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function validateToken($post, $token)
    {
        if (!$post || !is_string($token) || !Str::contains($token, '.')) {
            return false;
        }
        $payload = Str::before($token, '.');
        if (!hash_equals(hash_hmac('sha256', $payload, self::secret($post)), Str::after($token, '.'))) {
            return false;
        }
        $data = json_decode(self::b64decode($payload), true);
        if (!is_array($data) || (int) Arr::get($data, 'id') !== (int) $post->ID) {
            return false;
        }
        return (int) Arr::get($data, 'exp') >= time();
    }

    public static function cookieUnlocked($postId)
    {
        $postId = absint($postId);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is HMAC-validated below
        $cookie = sanitize_text_field(wp_unslash(Arr::get($_COOKIE, 'fp_unlock_' . $postId, '')));
        if ($cookie === '') {
            return false;
        }
        return self::validateToken(get_post($postId), $cookie);
    }

    public static function isRateLimited($id)
    {
        $max = (int) apply_filters('fluent_player/unlock_rate_limit', 8);
        return (int) get_transient(self::rateKey($id)) >= $max;
    }

    public static function bumpRateLimit($id)
    {
        $key = self::rateKey($id);
        set_transient($key, ((int) get_transient($key)) + 1, 5 * MINUTE_IN_SECONDS);
    }

    protected static function rateKey($id)
    {
        $ip = sanitize_text_field(wp_unslash(Arr::get($_SERVER, 'REMOTE_ADDR', '')));
        $ip = (string) apply_filters('fluent_player/unlock_rate_key', $ip, (int) $id);
        return 'fp_unlock_' . md5($ip . '|' . (int) $id);
    }

    // Keyed with post_password so a password change invalidates old tokens.
    protected static function secret($post)
    {
        return wp_salt('auth') . '|' . (string) $post->post_password;
    }

    /**
     * Issue a stateless access token for the unlisted media-fetch route — an
     * unguessable per-media reference that needs no server-side store. No TTL:
     * the token is printed into (possibly page-cached) markup and must not
     * expire under it. Bumping fluent_player_access_key_version revokes every
     * outstanding token site-wide.
     *
     * @param int $postId
     * @return string
     */
    public static function issueAccessToken($postId)
    {
        $postId = absint($postId);
        if (!$postId) {
            return '';
        }
        $payload = self::b64encode((string) wp_json_encode(['id' => $postId]));
        return $payload . '.' . hash_hmac('sha256', $payload, self::accessSecret());
    }

    /**
     * Verify an access token was issued for this exact media by this site.
     *
     * @param int    $postId
     * @param string $token
     * @return bool
     */
    public static function validateAccessToken($postId, $token)
    {
        $postId = absint($postId);
        if (!$postId || !is_string($token) || !Str::contains($token, '.')) {
            return false;
        }
        $payload = Str::before($token, '.');
        if (!hash_equals(hash_hmac('sha256', $payload, self::accessSecret()), Str::after($token, '.'))) {
            return false;
        }
        $data = json_decode(self::b64decode($payload), true);
        return is_array($data) && (int) Arr::get($data, 'id') === $postId;
    }

    // Rotatable site secret: bumping the version option invalidates all tokens.
    protected static function accessSecret()
    {
        $version = (string) get_option('fluent_player_access_key_version', '1');
        return wp_salt('auth') . '|fp_access|' . $version;
    }

    /**
     * Must outlive the page cache — LiteSpeed defaults to a week — or the refresh 403s
     * exactly when the cached markup has gone stale, which is the bug this exists to fix.
     * Shortening this below the cache TTL reintroduces it.
     *
     * Expiry is NOT the revocation lever. PlaybackController re-checks post status,
     * password and fluent_player/can_view_media on every redemption, so an operator who
     * unpublishes, locks, or filters out a media cuts off outstanding grants immediately.
     * That per-request re-check is what makes a long TTL acceptable.
     *
     * What it does NOT cover: entitlement that lives on the PAGE rather than the media
     * (membership tier, drip, purchase). A grant sits in publicly cached HTML, so treat it
     * as a bearer token for as long as it lasts — an integration gating access per user
     * must implement fluent_player/can_view_media, which is re-applied on every redemption.
     */
    const DEFAULT_GRANT_TTL = 30 * DAY_IN_SECONDS;
    const MIN_GRANT_TTL     = 5 * MINUTE_IN_SECONDS;
    const MAX_GRANT_TTL     = 90 * DAY_IN_SECONDS;

    /**
     * @return int
     */
    public static function playbackGrantTtl()
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $ttl = (int) apply_filters('fluent_player/playback_grant_ttl', self::DEFAULT_GRANT_TTL);

        return max(self::MIN_GRANT_TTL, min($ttl, self::MAX_GRANT_TTL));
    }

    /**
     * A time-bounded capability to have THIS media's source re-signed, and nothing else.
     *
     * Bound to a single media id, so a grant taken from one embedded player cannot be
     * used to enumerate the rest of the library.
     *
     * @param int      $postId
     * @param int|null $expiresAt Absolute expiry; defaults to now + playbackGrantTtl().
     * @return string
     */
    public static function issuePlaybackGrant($postId, $expiresAt = null)
    {
        $postId = absint($postId);
        if (!$postId) {
            return '';
        }

        $payload = self::b64encode((string) wp_json_encode([
            'id'  => $postId,
            'exp' => $expiresAt === null
                ? time() + self::playbackGrantTtl()
                : min(time() + self::MAX_GRANT_TTL, (int) $expiresAt),
        ]));

        return $payload . '.' . hash_hmac('sha256', $payload, self::playbackGrantSecret());
    }

    /**
     * @param int    $postId
     * @param string $grant
     * @return bool
     */
    public static function validatePlaybackGrant($postId, $grant)
    {
        $postId = absint($postId);
        if (!$postId || !is_string($grant) || !Str::contains($grant, '.')) {
            return false;
        }

        $payload = Str::before($grant, '.');
        if (!hash_equals(hash_hmac('sha256', $payload, self::playbackGrantSecret()), Str::after($grant, '.'))) {
            return false;
        }

        $data = json_decode(self::b64decode($payload), true);
        if (!is_array($data) || (int) Arr::get($data, 'id') !== $postId) {
            return false;
        }

        // Absent exp is malformed, not legacy: treating it as valid would be unbounded.
        $exp = (int) Arr::get($data, 'exp', 0);

        return $exp > 0 && time() < $exp;
    }

    /**
     * Distinct from accessSecret() so a TTL-less access token cannot verify as a grant.
     *
     * @return string
     */
    protected static function playbackGrantSecret()
    {
        $version = (string) get_option('fluent_player_access_key_version', '1');
        return wp_salt('auth') . '|fp_playback_grant|' . $version;
    }

    protected static function b64encode($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    protected static function b64decode($encoded)
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'));
    }
}
