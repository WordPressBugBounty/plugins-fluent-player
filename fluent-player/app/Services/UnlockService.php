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

    protected static function b64encode($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    protected static function b64decode($encoded)
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'));
    }
}
