<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Support\Arr;

/**
 * Resolves an effective player source for the block and shortcode dynamic
 * source paths. Returns settings overrides (`src` + `provider`) or null when
 * no usable override is available — the caller falls back to saved media.
 *
 * Precedence: explicit $sourceUrl → $sourceMeta on current post → null.
 */
class DynamicMediaSourceResolver
{
    private const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'oga', 'flac'];

    private const PRO_HOST_HINTS = [
        'stream.mux.com'       => 'mux',
        'image.mux.com'        => 'mux',
        'b-cdn.net'            => 'bunny',
        'bunnycdn.com'         => 'bunny',
        'bunny.net'            => 'bunny',
        'mediadelivery.net'    => 'bunny',
        'cloudflarestream.com' => 'cloudflare_stream',
        'videodelivery.net'    => 'cloudflare_stream',
        'gumlet.io'            => 'gumlet',
    ];

    private const STATIC_PRECONNECT = [
        'youtube'     => ['https://www.youtube.com', 'https://i.ytimg.com'],
        'vimeo'       => ['https://player.vimeo.com', 'https://i.vimeocdn.com'],
        'mux'         => ['https://stream.mux.com', 'https://image.mux.com'],
        'mux_stream'  => ['https://stream.mux.com', 'https://image.mux.com'],
        'gumlet'      => ['https://video.gumlet.io', 'https://stream.gumlet.io'],
        'gumlet_live' => ['https://video.gumlet.io', 'https://stream.gumlet.io'],
    ];

    /**
     * Origins worth a preconnect hint for a media's playback source.
     * Matches on the saved provider first, then on the src host via classify(),
     * so legacy rows with a stale provider still resolve. Never preconnects
     * for library media (`wordpress`) — that src is same-origin.
     *
     * @param string $provider Saved `settings.provider`.
     * @param string $src      Saved `settings.src`.
     * @param array  $settings Full settings; only `bunny_storage.url` is read.
     * @return array Origin strings, empty when no hint applies.
     */
    public static function preconnectDomains($provider, $src, $settings = [])
    {
        $classification = self::classify($src);
        $hinted = $classification
            ? Arr::get($classification, 'providerHint', $classification['provider'])
            : '';

        if ($provider === 'youtube' || $hinted === 'youtube') {
            return self::STATIC_PRECONNECT['youtube'];
        }

        if ($provider === 'vimeo' || $hinted === 'vimeo') {
            return self::STATIC_PRECONNECT['vimeo'];
        }

        if ($provider === 'bunny' || $provider === 'bunny_storage') {
            $hint = $src;
            // The storage proxy path is same-origin; the pull-zone URL in
            // settings is the host actually fetched from.
            if (!$hint || strpos($hint, 'bunny/storage/stream') !== false) {
                $hint = Arr::get($settings, 'bunny_storage.url', '');
            }
            return self::originOf($hint);
        }

        if ($hinted === 'bunny') {
            return self::originOf($src);
        }

        if ($provider === 'mux' || $provider === 'mux_stream' || $hinted === 'mux') {
            return self::STATIC_PRECONNECT['mux'];
        }

        if ($provider === 'gumlet' || $provider === 'gumlet_live' || $hinted === 'gumlet') {
            return self::STATIC_PRECONNECT['gumlet'];
        }

        if ($provider === 'cloudflare_r2'
            || $provider === 'cloudflare_stream'
            || $hinted === 'cloudflare_stream'
            || $provider === 'external'
        ) {
            return self::originOf($src);
        }

        return [];
    }

    private static function originOf($url)
    {
        $parsed = $url ? wp_parse_url($url) : false;
        if (!is_array($parsed) || empty($parsed['host'])) {
            return [];
        }

        $scheme = !empty($parsed['scheme']) && is_string($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $port = !empty($parsed['port']) ? ':' . $parsed['port'] : '';

        return [$scheme . '://' . $parsed['host'] . $port];
    }

    /**
     * Classify any URL into a provider + media type. Never fetches the URL.
     *
     * @param string $url
     * @return array|null `{provider, mediaType, youtubeId?, isHls?, providerHint?}`
     *                    or null when the URL isn't playable (non-http/https).
     */
    public static function classify($url)
    {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '' || !self::isPlayableUrl($url)) {
            return null;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $provider = self::detectProvider($host);

        $result = [
            'provider'  => $provider,
            'mediaType' => 'video',
        ];

        if ($provider === 'youtube') {
            $result['youtubeId'] = Helper::extractYouTubeVideoId($url);
            return $result;
        }

        if ($provider === 'external') {
            $extension = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                $result['mediaType'] = 'audio';
            }
            if ($extension === 'm3u8') {
                $result['isHls'] = true;
            }
            // A pro provider likely manages this host (signing, analytics).
            // Informational only — the URL still plays as plain external.
            foreach (self::PRO_HOST_HINTS as $hintHost => $hintProvider) {
                if ($host === $hintHost || substr($host, -strlen('.' . $hintHost)) === '.' . $hintHost) {
                    $result['providerHint'] = $hintProvider;
                    break;
                }
            }
        }

        return $result;
    }

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
        $youtubeId = ($provider === 'youtube') ? Helper::extractYouTubeVideoId($url) : '';
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

        // Direct file/stream URL — plays through the same pipeline as
        // self-hosted media but without a library attachment.
        return 'external';
    }

    private static function pickPoster($explicit, $youtubeId)
    {
        $explicit = is_string($explicit) ? trim($explicit) : '';
        if ($explicit !== '' && self::isPlayableUrl($explicit)) {
            return $explicit;
        }
        return $youtubeId ? Helper::getYouTubeFallbackPosterUrl($youtubeId) : '';
    }
}
