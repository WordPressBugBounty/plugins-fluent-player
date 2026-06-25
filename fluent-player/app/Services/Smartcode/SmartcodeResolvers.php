<?php

namespace FluentPlayer\App\Services\Smartcode;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Str;

/**
 * Value resolvers for the core Fluent Player shortcode namespaces. Bound to the
 * registry's namespace definitions; each method has the resolver signature
 * function (string $key, string $fallback, array $context): string.
 */
class SmartcodeResolvers
{
    public static function user($key, $fallback, $context)
    {
        // Arr::exists, not Arr::get with a default — an explicit ['user' => null]
        // (logged-out) must stay null, only an absent key falls back to current.
        $user = Arr::exists($context, 'user') ? $context['user'] : wp_get_current_user();

        if (!$user || empty($user->ID)) {
            return $fallback;
        }

        $map = [
            'display_name' => $user->display_name,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'email'        => $user->user_email,
            'login'        => $user->user_login,
            'id'           => $user->ID,
            'role'         => Arr::first((array) $user->roles, null, ''),
        ];

        return self::valueOrFallback(Arr::get($map, $key, ''), $fallback);
    }

    public static function site($key, $fallback, $context)
    {
        $map = [
            'name'        => get_bloginfo('name'),
            'tagline'     => get_bloginfo('description'),
            'url'         => home_url(),
            'admin_email' => get_bloginfo('admin_email'),
        ];

        return self::valueOrFallback(Arr::get($map, $key, ''), $fallback);
    }

    public static function date($key, $fallback, $context)
    {
        if ($key === 'now') {
            return date_i18n(get_option('date_format'));
        }

        if ($key === 'year') {
            return date_i18n('Y');
        }

        if (Str::startsWith($key, 'format.')) {
            $format = Str::after($key, 'format.');
            return $format !== '' ? date_i18n($format) : $fallback;
        }

        return $fallback;
    }

    public static function media($key, $fallback, $context)
    {
        $media = Arr::get($context, 'media');

        if (!$media) {
            return $fallback;
        }

        if (is_object($media)) {
            $mediaId = isset($media->ID) ? $media->ID : (isset($media->id) ? $media->id : null);
        } elseif (is_numeric($media)) {
            $mediaId = (int) $media;
        } else {
            $mediaId = null;
        }

        if (!$mediaId) {
            return $fallback;
        }

        $post = get_post($mediaId);
        if (!$post) {
            return $fallback;
        }

        $map = [
            'title'  => $post->post_title,
            'author' => get_the_author_meta('display_name', $post->post_author),
            'date'   => date_i18n(get_option('date_format'), strtotime($post->post_date)),
            'id'     => $post->ID,
        ];

        return self::valueOrFallback(Arr::get($map, $key, ''), $fallback);
    }

    public static function valueOrFallback($value, $fallback)
    {
        if ($value === '' || $value === null) {
            return $fallback;
        }

        return (string) $value;
    }
}
