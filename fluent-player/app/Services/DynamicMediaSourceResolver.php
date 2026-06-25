<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

/**
 * Resolves an effective player source for the block and shortcode dynamic
 * source paths. Returns settings overrides (`src` + `provider`) or null when
 * no usable override is available — the caller falls back to saved media.
 *
 * Precedence: explicit $sourceUrl → $sourceMeta on current post → null.
 */
class DynamicMediaSourceResolver
{
    /**
     * @param string $sourceUrl    Explicit URL from the shortcode/block.
     * @param string $sourceMeta   Post meta key read from the current post.
     * @param string $sourcePoster Explicit poster URL.
     * @return array|null Overrides with `src`, `provider`, `posterSrc`, or null.
     */
    public static function resolve($sourceUrl = '', $sourceMeta = '', $sourcePoster = '')
    {
        $url = self::pickUrl($sourceUrl, $sourceMeta);
        if ($url === '' || !self::isPlayableUrl($url)) {
            return null;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $provider = self::detectProvider($host);

        // YouTube `?list=`/`&start_radio=` params hang Vidstack's iframe.
        // Extract the ID once: drives the canonical `src` and the poster.
        $youtubeId = ($provider === 'youtube') ? self::extractYoutubeId($url) : '';
        $youtubeHost = (strpos($host, 'youtube-nocookie.com') !== false)
            ? 'www.youtube-nocookie.com'
            : 'www.youtube.com';

        $overrides = [
            'src'       => $youtubeId ? 'https://' . $youtubeHost . '/watch?v=' . $youtubeId : $url,
            'provider'  => $provider,
            'posterSrc' => self::pickPoster($sourcePoster, $youtubeId),
        ];

        // Listeners may return null to discard the default — the caller treats
        // null as "no override" and falls back to the saved media.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return apply_filters('fluent_player/dynamic_source_overrides', $overrides, $url, $sourceUrl, $sourceMeta, $sourcePoster);
    }

    private static function pickUrl($sourceUrl, $sourceMeta)
    {
        $sourceUrl = is_string($sourceUrl) ? trim($sourceUrl) : '';
        if ($sourceUrl !== '') {
            return $sourceUrl;
        }

        $sourceMeta = is_string($sourceMeta) ? trim($sourceMeta) : '';
        if ($sourceMeta === '' || preg_match('/[^A-Za-z0-9_.:\/\-]/', $sourceMeta)) {
            return '';
        }
        // Underscore-prefixed keys are WordPress-protected by convention
        // (`_thumbnail_id`, `_edit_lock`, etc.). Opt-in via the filter.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        if (strpos($sourceMeta, '_') === 0 && !apply_filters('fluent_player/dynamic_source_meta_key_allowed', false, $sourceMeta)) {
            return '';
        }

        $postId = (int) get_the_ID();
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $postId = (int) apply_filters('fluent_player/dynamic_source_post_id', $postId, $sourceMeta);
        if (!$postId) {
            return '';
        }

        $value = get_post_meta($postId, $sourceMeta, true);
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private static function isPlayableUrl($url)
    {
        $parsed = wp_parse_url($url);
        if (!is_array($parsed) || empty($parsed['host']) || empty($parsed['scheme'])) {
            return false;
        }

        $scheme = strtolower((string) $parsed['scheme']);
        return $scheme === 'http' || $scheme === 'https';
    }

    private static function detectProvider($host)
    {
        if (strpos($host, 'youtube.com') !== false
            || strpos($host, 'youtube-nocookie.com') !== false
            || strpos($host, 'youtu.be') !== false
        ) {
            return 'youtube';
        }

        if (strpos($host, 'vimeo.com') !== false) {
            return 'vimeo';
        }

        return 'wordpress';
    }

    private static function pickPoster($explicit, $youtubeId)
    {
        $explicit = is_string($explicit) ? trim($explicit) : '';
        if ($explicit !== '' && self::isPlayableUrl($explicit)) {
            return $explicit;
        }
        // `hqdefault.jpg` (480x360) is always present; `maxresdefault.jpg` 404s
        // for non-HD / short / mobile uploads, leaving a broken poster.
        return $youtubeId ? 'https://img.youtube.com/vi/' . $youtubeId . '/hqdefault.jpg' : '';
    }

    private static function extractYoutubeId($url)
    {
        // Same canonical-URL pattern set used by player.php's privacy-mode block.
        $pattern = '/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|live\/|shorts\/|watch\?v=)|youtube-nocookie\.com\/(?:embed\/|v\/|watch\?v=))([^&\n?#]+)/';
        if (!preg_match($pattern, $url, $matches) || empty($matches[1])) {
            return '';
        }
        return $matches[1];
    }
}
