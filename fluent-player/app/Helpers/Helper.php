<?php

namespace FluentPlayer\App\Helpers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;
use FluentPlayer\Framework\Support\Arr;

class Helper
{
    /**
     * Default brand color (matches Fluent Player logo)
     */
    const DEFAULT_BRAND_COLOR = '#DD1F13';

    /**
     * Canonical handle for the timed-content frontend script.
     *
     * Every render path (free single media, free FluentCommunity block, pro
     * TimedContentHandler) enqueues the asset under THIS one handle via
     * enqueueTimedContentScript(), so WordPress dedupes them to a single tag.
     * Previously each site used its own handle and the module was emitted twice.
     */
    const TIMED_CONTENT_SCRIPT_HANDLE = 'fluent_player_timed_content';

    /**
     * Enqueue the timed-content frontend script under the canonical handle.
     *
     * Single seam shared by free and pro. Idempotent — repeated calls register
     * one handle (WordPress dedupes by handle). Depends on the core player
     * script so timed content initialises after the player API is available.
     *
     * @return void
     */
    public static function enqueueTimedContentScript()
    {
        Enqueue::script(
            self::TIMED_CONTENT_SCRIPT_HANDLE,
            'js/timed-content-frontend.js',
            ['fluent_player'],
            FLUENT_PLAYER_VERSION,
            true
        );
    }

    public static function safeUnserialize($data)
    {
        if (!is_string($data) || !is_serialized($data)) {
            return $data;
        }

        return unserialize($data, ['allowed_classes' => false]);
    }

    /**
     * Privacy-Enhanced Mode for YouTube poster thumbnails.
     *
     * YouTube thumbnails are stored on img.youtube.com, which is contacted on page
     * load (before any playback) and can set cookies. When privacy mode is on, swap
     * that host for the cookieless static CDN i.ytimg.com — same path, same image, no
     * youtube.com contact until the visitor plays. Non-YouTube posters (Mux, Bunny,
     * custom uploads) and already-cookieless i.ytimg.com URLs pass through untouched.
     *
     * The player embed host is handled separately via the Vidstack `cookies` flag
     * (see resources/js/utils/videoSrc.js -> applyYouTubePrivacyMode).
     *
     * @param string $url         Poster URL.
     * @param bool   $privacyMode Whether YouTube Privacy-Enhanced Mode is enabled.
     * @return string
     */
    public static function privacyEnhanceYouTubePoster($url, $privacyMode)
    {
        if (!$privacyMode || !$url) {
            return $url;
        }

        return preg_replace('#^https?://img\.youtube\.com/#i', 'https://i.ytimg.com/', $url);
    }

    /**
     * Gather rest info/settings for http client.
     * @return array
     */
    public static function getRestInfo()
    {
        $app = App::make();
        $config = $app->config;

        $ns = $config->get('app.rest_namespace');
        $ver = $config->get('app.rest_version');

        return [
            'base_url'  => self::getBaseRestUrl(),
            'url'       => self::getFullRestUrl($ns, $ver),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version'   => $ver
        ];
    }

    /**
     * Get base rest url by examining the permalink.
     * @return string
     */
    protected static function getBaseRestUrl()
    {
        if (get_option('permalink_structure')) {
            return esc_url_raw(rest_url());
        }

        return esc_url_raw(
            rtrim(get_site_url(), '/') . "/?rest_route=/"
        );
    }

    /**
     * Get the full rest url by examining the permalink
     * (full means, including the namespace/version).
     *
     * @param $ns Rest Namespace
     * @param $ver Rest Version
     *
     * @return string
     */
    protected static function getFullRestUrl($ns, $ver)
    {
        if (get_option('permalink_structure')) {
            return esc_url_raw(rest_url($ns . '/' . $ver));
        }

        return esc_url_raw(
            rtrim(get_site_url(), '/') . "/?rest_route=/{$ns}/{$ver}"
        );
    }

    /**
     * get Settings
     * @return array
     */
    public static function getSettings()
    {
        return \FluentPlayer\App\Services\SettingsService::getSettings();
    }

    /**
     * Enqueue player-specific styles
     *
     * @param int $mediaId
     * @param array $settings
     * @param array $defaultSettings
     * @param bool $returnCss If true, returns CSS as string instead of enqueueing
     * @return string|void Returns CSS string if $returnCss is true, otherwise void
     */
    public static function enqueuePlayerStyles($mediaId, $settings, $defaultSettings, $returnCss = false) {
        if (!did_action('wp_enqueue_scripts') && !$returnCss) {
            add_action('wp_enqueue_scripts', function() use ($mediaId, $settings, $defaultSettings) {
                self::enqueuePlayerStyles($mediaId, $settings, $defaultSettings);
            });
            return;
        }

        $customCss = '';

        // Add branding color: if custom branding ON use custom color, otherwise use global
        $useCustomBranding = Arr::get($settings, 'useCustomBranding', false);
        $brandingColor = $useCustomBranding
            ? (Arr::get($settings, 'brandingColor') ?: '#DD1F13')
            : Arr::get($defaultSettings, 'brandColor', '');
        if ($brandingColor) {
            $customCss .= "
                .fluent-player-container[data-media-id=\"" . esc_attr($mediaId) . "\"] {
                    --media-brand: " . esc_attr($brandingColor) . ";
                    --media-focus-ring: 0 0 0 2px color-mix(in srgb, " . esc_attr($brandingColor) . " 72%, white), 0 0 0 4px color-mix(in srgb, " . esc_attr($brandingColor) . " 32%, transparent);
                }
            ";
        }

        // Add control bar color: if custom branding ON use custom color, otherwise use global
        $controlBarColor = $useCustomBranding
            ? Arr::get($settings, 'controlBarColor', '')
            : Arr::get($defaultSettings, 'controlBarColor', '');
        if ($controlBarColor) {
            $customCss .= "
                .fluent-player-container[data-media-id=\"" . esc_attr($mediaId) . "\"] {
                    --fp-control-bar-bg: " . esc_attr($controlBarColor) . ";
                }
            ";
        }


        // Add aspect ratio to parent container to prevent layout shifts (video only)
        // Check if this is a video player (not audio)
        $viewType = Arr::get($settings, 'viewType', 'video');
        $isVideo = $viewType !== 'audio';

        if ($isVideo) {
            $aspectRatio = Arr::get($defaultSettings, 'aspectRatio');
            if ($aspectRatio && $aspectRatio != 'original') {
                // Normalize aspect ratio format: convert '16:9' to '16 / 9' for CSS
                // SettingsService may return either format depending on the source
                $cssAspectRatio = str_replace(':', ' / ', $aspectRatio);
                $customCss .= "
                    .fluent-player-container[data-media-id=\"" . esc_attr($mediaId) . "\"] {
                        aspect-ratio: " . esc_attr($cssAspectRatio) . ";
                    }
                    .fluent-player-container[data-media-id=\"" . esc_attr($mediaId) . "\"] media-player[data-view-type='video'] {
                        aspect-ratio: " . esc_attr($cssAspectRatio) . ";
                    }
                ";
            } else {
                // For 'original' aspect ratio, use 16:9 as a sensible default to prevent layout shifts
                // This provides a stable container while the video loads and reveals its natural dimensions
                // 16:9 is the most common video aspect ratio for modern content
                $customCss .= "
                    .fluent-player-container[data-media-id=\"" . esc_attr($mediaId) . "\"] {
                        aspect-ratio: 16 / 9;
                    }
                    .fluent-player-container[data-media-id=\"" . esc_attr($mediaId) . "\"] media-player[data-view-type='video'] {
                        aspect-ratio: 16 / 9;
                    }

                    /* Allow video to override with its natural aspect ratio once loaded */
                    .fluent-player-container[data-media-id=\"" . esc_attr($mediaId) . "\"] media-player[data-view-type='video'].can-play {
                        aspect-ratio: auto;
                    }
                ";
            }
        }


        // Add the dynamic CSS or return it
        if (!empty($customCss)) {
            $customCss = preg_replace('/\s+/', ' ', trim($customCss));
            if ($returnCss) {
                return $customCss;
            } else {
                // Try to add inline style to the base CSS handle first
                if (wp_style_is('fluent_player_css', 'registered') || wp_style_is('fluent_player_css', 'enqueued')) {
                    wp_add_inline_style('fluent_player_css', $customCss);
                } else {
                    // For custom pages or when wp_head doesn't work, output CSS directly
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is already sanitized
                    echo '<style id="fluent-player-dynamic-css-' . esc_attr($mediaId) . '">' . $customCss . '</style>';
                }
            }
        }

        // Return empty string if $returnCss is true but no CSS was generated
        if ($returnCss) {
            return '';
        }
    }

    /**
     * Sanitize HTML content for Fluent Player with Vidstack support
     *
     * @param string $html
     * @return string
     */
    public static function fluentPlayerSanitizeHtml($html)
    {
        if (!$html) {
            return $html;

        }

        // Remove event handlers (except those we specifically allow)
        $html = preg_replace('/\s+on(?!click)[a-z]+\s*=\s*([\'"])[^\'"]*\1/i', '', $html);
        // Remove JavaScript protocol
        $html = preg_replace('/\bjavascript\s*:/i', '', $html);

        // Get standard allowed tags
        $tags = wp_kses_allowed_html('post');

        // Allow style tags
        $tags['style'] = [
            'types' => [],
        ];

        // iframe configuration
        $tags['iframe'] = [
            'width'           => [],
            'height'          => [],
            'src'             => [],
            'srcdoc'          => [],
            'title'           => [],
            'frameborder'     => [],
            'allow'           => [],
            'class'           => [],
            'id'              => [],
            'allowfullscreen' => [],
            'style'           => [],
            'tabindex'        => [],
            'aria-hidden'     => [],
            'data-no-controls' => [],
        ];

        // Button configuration
        $tags['button']['onclick'] = [];

        // Standard HTML elements that Vidstack uses
        $tags['div'] = [
            'class' => [],
            'style' => [],
            'id' => [],
            'role' => [],
            'tabindex' => [],
            'aria-hidden' => [],
            'aria-live' => [],
            'aria-atomic' => [],
            'data-*' => true,
        ];

        $tags['span'] = [
            'class' => [],
            'style' => [],
            'id' => [],
            'role' => [],
            'aria-hidden' => [],
            'data-*' => true,
        ];

        $tags['img'] = [
            'src' => [],
            'alt' => [],
            'class' => [],
            'style' => [],
            'width' => [],
            'height' => [],
            'loading' => [],
            'decoding' => [],
            'data-*' => true,
        ];

        $tags['template'] = [
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        $tags['slot'] = [
            'name' => [],
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        // Essential Vidstack attributes - used across all media elements
        $vidstack_attrs = [
            // Core HTML attributes
            'id' => [],
            'class' => [],
            'style' => [],
            'title' => [],
            'hidden' => [],
            'tabindex' => [],
            'disabled' => [],
            'readonly' => [],

            // Media-specific attributes
            'src' => [],
            'poster' => [],
            'autoplay' => [],
            'loop' => [],
            'muted' => [],
            'preload' => [],
            'crossorigin' => [],
            'playsinline' => [],
            'controls' => [],
            'width' => [],
            'height' => [],
            'type' => [],
            'min' => [],
            'max' => [],
            'step' => [],
            'value' => [],
            'seconds' => [],
            'thumbnails' => [],
            'chapters' => [],
            'placement' => [],
            'when' => [],
            'event' => [],
            'action' => [],
            'offset' => [],
            'duration' => [],
            'keep-alive' => [],
            'no-clamp' => [],
            'load' => [],

            // Essential ARIA attributes
            'role' => [],
            'aria-label' => [],
            'aria-labelledby' => [],
            'aria-describedby' => [],
            'aria-expanded' => [],
            'aria-hidden' => [],
            'aria-pressed' => [],
            'aria-disabled' => [],
            'aria-valuemin' => [],
            'aria-valuemax' => [],
            'aria-valuenow' => [],
            'aria-valuetext' => [],
            'aria-orientation' => [],
            'aria-live' => [],
            'aria-atomic' => [],
            'aria-keyshortcuts' => [],

            // All data attributes (crucial for Vidstack)
            'data-*' => true,
        ];

        // Core Vidstack elements (based on official documentation)
        $core_elements = [
            // Core player components
            'media-player',
            'media-provider',

            // Display components
            'media-poster',
            'media-gesture',
            'media-captions',
            'media-title',
            'media-time',
            'media-chapter-title',
            'media-announcer',
            'media-buffering-indicator',
            'media-thumbnail',
            'media-track',

            // Controls
            'media-controls',
            'media-controls-group',

            // Buttons
            'media-play-button',
            'media-mute-button',
            'media-caption-button',
            'media-pip-button',
            'media-fullscreen-button',
            'media-live-button',
            'media-seek-button',
            'media-airplay-button',

            // Sliders
            'media-time-slider',
            'media-volume-slider',
            'media-slider-preview',
            'media-slider-value',
            'media-slider-thumbnail',
            'media-slider-chapters',
            'media-slider-steps',

            // Tooltips
            'media-tooltip',
            'media-tooltip-trigger',
            'media-tooltip-content',

            // Icons
            'media-icon',

            // Menus
            'media-menu',
            'media-menu-button',
            'media-menu-item',
            'media-menu-portal',
            'media-radio-group',
            'media-radio',

            // Specific radio groups
            'media-audio-radio-group',
            'media-captions-radio-group',
            'media-chapters-radio-group',
            'media-quality-radio-group',
            'media-speed-radio-group',

            // Layouts (commonly used)
            'media-video-layout',
            'media-audio-layout',

            // Loading states
            'media-spinner',
            'media-live-indicator',
        ];

        // Add all core elements with essential attributes
        foreach ($core_elements as $element) {
            $tags[$element] = $vidstack_attrs;
        }

        // SVG elements for icons (streamlined)
        $tags['svg'] = [
            'width' => [],
            'height' => [],
            'viewBox' => [],
            'fill' => [],
            'stroke' => [],
            'xmlns' => [],
            'aria-hidden' => [],
            'aria-label' => [],
            'focusable' => [],
            'role' => [],
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        $tags['path'] = [
            'd' => [],
            'fill' => [],
            'stroke' => [],
            'stroke-width' => [],
            'stroke-linecap' => [],
            'stroke-linejoin' => [],
            'fill-rule' => [],
            'clip-rule' => [],
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        $tags['g'] = [
            'fill' => [],
            'stroke' => [],
            'transform' => [],
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        $tags['circle'] = [
            'cx' => [],
            'cy' => [],
            'r' => [],
            'fill' => [],
            'stroke' => [],
            'stroke-width' => [],
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        $tags['rect'] = [
            'x' => [],
            'y' => [],
            'width' => [],
            'height' => [],
            'rx' => [],
            'ry' => [],
            'fill' => [],
            'stroke' => [],
            'stroke-width' => [],
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        $tags['use'] = [
            'href' => [],
            'xlink:href' => [],
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        $tags['defs'] = [
            'class' => [],
            'style' => [],
            'id' => [],
            'data-*' => true,
        ];

        // Apply custom filters
        $tags = apply_filters('fluent_player/allowed_html_tags', $tags);

        return wp_kses($html, $tags);
    }

    /**
     * Format duration in seconds to HH:MM:SS format
     *
     * @param int|float $duration Duration in seconds
     * @return string Formatted duration (e.g., "0:05:30", "1:30:00")
     */
    public static function formatDuration($duration)
    {
        if (!is_numeric($duration) || $duration < 0) {
            return '0:00:00';
        }

        $seconds = (int) floor((float) $duration);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    }


    /**
     * Check if pro version is active
     *
     * @return bool
     */
    public static function hasPro()
    {
        return defined('FLUENT_PLAYER_PRO_VERSION');
    }

    /**
     * Get available languages for language switcher
     *
     * @return array
     */
    public static function getLanguages()
    {
        // Start with our curated top languages with proper names
        $topLanguages = self::getTopLanguagesWithFlags();
        $languages = [];

        foreach ($topLanguages as $code => $data) {
            $languages[$code] = $data['name'];
        }

        // Get available WordPress translations for additional languages
        if (function_exists('wp_get_available_translations')) {
            $translations = wp_get_available_translations();

            // Add WordPress translations that aren't in our top list
            foreach ($translations as $code => $translation) {
                if (!isset($languages[$code])) {
                    $languages[$code] = $translation['english_name'];
                }
            }
        }

        // Sort by name while keeping English (US) first
        $englishUS = $languages['en_US'] ?? null;
        unset($languages['en_US']);
        asort($languages);

        if ($englishUS) {
            $languages = ['en_US' => $englishUS] + $languages;
        }

        return $languages;
    }

    /**
     * Get top 10 most spoken languages with flag information
     *
     * @return array
     */
    public static function getTopLanguagesWithFlags()
    {
        return [
            // Top 10 most spoken languages worldwide
            'en_US' => [
                'name' => 'English',
                'flag' => '🇺🇸',
                'code' => 'en'
            ],
            'zh_CN' => [
                'name' => 'Chinese (Mandarin)',
                'flag' => '🇨🇳',
                'code' => 'zh'
            ],
            'hi_IN' => [
                'name' => 'Hindi',
                'flag' => '🇮🇳',
                'code' => 'hi'
            ],
            'es_ES' => [
                'name' => 'Spanish',
                'flag' => '🇪🇸',
                'code' => 'es'
            ],
            'fr_FR' => [
                'name' => 'French',
                'flag' => '🇫🇷',
                'code' => 'fr'
            ],
            'ar' => [
                'name' => 'Arabic',
                'flag' => '🇸🇦',
                'code' => 'ar'
            ],
            'bn_BD' => [
                'name' => 'Bengali',
                'flag' => '🇧🇩',
                'code' => 'bn'
            ],
            'ru_RU' => [
                'name' => 'Russian',
                'flag' => '🇷🇺',
                'code' => 'ru'
            ],
            'pt_BR' => [
                'name' => 'Portuguese',
                'flag' => '🇧🇷',
                'code' => 'pt'
            ],
            'ur_PK' => [
                'name' => 'Urdu',
                'flag' => '🇵🇰',
                'code' => 'ur'
            ]
        ];
    }

    /**
     * Get language flag by language code
     *
     * @param string $langCode
     * @return string
     */
    public static function getLanguageFlag($langCode)
    {
        $topLanguages = self::getTopLanguagesWithFlags();
        $langCode = $langCode !== null ? (string) $langCode : '';

        if (isset($topLanguages[$langCode])) {
            return $topLanguages[$langCode]['flag'];
        }

        // Fallback for other languages - try to match by base language code
        $baseLangCode = $langCode !== '' ? explode('_', $langCode)[0] : '';
        foreach ($topLanguages as $code => $data) {
            if ($data['code'] === $baseLangCode) {
                return $data['flag'];
            }
        }

        // Default flag for unknown languages
        return '🌐';
    }

    /**
     * Get complete language display information
     *
     * @param string $langCode Language code (e.g., 'en_US', 'es_ES')
     * @return array Array with 'name', 'flag', and 'code' keys
     */
    public static function getLanguageInfo($langCode)
    {
        $languageNames = self::getLanguages();
        $langCode = $langCode !== null ? (string) $langCode : '';
        return [
            'name' => $languageNames[$langCode] ?? $langCode,
            'flag' => self::getLanguageFlag($langCode),
            'code' => $langCode !== '' ? strtoupper((string) (explode('_', $langCode)[0])) : ''
        ];
    }

    /**
     * Get SVG icons for common usage
     *
     * @return array
     */
    public static function getSvgIcons()
    {
        return [
            'info-circle'     => '<path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/>',
            'question-circle' => '<path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM169.8 165.3c7.9-22.3 29.1-37.3 52.8-37.3h58.3c34.9 0 63.1 28.3 63.1 63.1c0 22.6-12.1 43.5-31.7 54.8L280 264.4c-.2 13-10.9 23.6-24 23.6c-13.3 0-24-10.7-24-24V250.5c0-8.6 4.6-16.5 12.1-20.8l44.3-25.4c4.7-2.7 7.6-7.7 7.6-13.1c0-8.4-6.8-15.1-15.1-15.1H222.6c-3.4 0-6.4 2.1-7.5 5.3l-.4 1.2c-4.4 12.5-18.2 19-30.6 14.6s-19-18.2-14.6-30.6l.4-1.2zM224 352a32 32 0 1 1 64 0 32 32 0 1 1 -64 0z"/>',
            'check-circle'    => '<path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM369 209L241 337c-9.4 9.4-24.6 9.4-33.9 0l-64-64c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l47 47L335 175c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9z"/>',
            'times-circle'    => '<path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM175 175c9.4-9.4 24.6-9.4 33.9 0l47 47 47-47c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9l-47 47 47 47c9.4 9.4 9.4 24.6 0 33.9s-24.6 9.4-33.9 0l-47-47-47 47c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9l47-47-47-47c-9.4-9.4-9.4-24.6 0-33.9z"/>',
            'star'            => '<path d="M316.9 18C311.6 7 300.4 0 288.1 0s-23.4 7-28.8 18L195 150.3 51.4 171.5c-12 1.8-22 10.2-25.7 21.7s-.7 24.2 7.9 32.7L137.8 329 113.2 474.7c-2 12 3 24.2 12.9 31.3s23 8 33.8 2.3l128.3-68.5 128.3 68.5c10.8 5.7 23.9 4.9 33.8-2.3s14.9-19.3 12.9-31.3L438.5 329 542.7 225.9c8.6-8.5 11.7-21.2 7.9-32.7s-13.7-19.9-25.7-21.7L381.2 150.3 316.9 18z"/>',
            'heart'           => '<path d="M47.6 300.4L228.3 469.1c7.5 7 17.4 10.9 27.7 10.9s20.2-3.9 27.7-10.9L464.4 300.4c30.4-28.3 47.6-68 47.6-109.5v-5.8c0-69.9-50.5-129.5-119.4-141C347 36.5 300.6 51.4 268 84L256 96 244 84c-32.6-32.6-79-47.5-124.6-39.9C50.5 55.6 0 115.2 0 185.1v5.8c0 41.5 17.2 81.2 47.6 109.5z"/>',
            'home'            => '<path d="M575.8 255.5c0 18-15 32.1-32 32.1h-32l.7 160.2c0 2.7-.2 5.4-.5 8.1V472c0 22.1-17.9 40-40 40H456c-1.1 0-2.2 0-3.3-.1c-1.4 .1-2.8 .1-4.2 .1H416 392c-22.1 0-40-17.9-40-40V448 384c0-17.7-14.3-32-32-32H256c-17.7 0-32 14.3-32 32v64 24c0 22.1-17.9 40-40 40H160 128.1c-1.5 0-3-.1-4.5-.2c-1.2 .1-2.4 .2-3.6 .2H104c-22.1 0-40-17.9-40-40V360c0-.9 0-1.9 .1-2.8V287.6H32c-18 0-32-14-32-32.1c0-9 3-17 10-24L266.4 8c7-7 15-8 22-8s15 2 21 7L564.8 231.5c8 7 12 15 11 24z"/>',
            'user'            => '<path d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8 304 0 383.8 0 482.3C0 498.7 13.3 512 29.7 512H418.3c16.4 0 29.7-13.3 29.7-29.7C448 383.8 368.2 304 269.7 304H178.3z"/>',
            'cog'             => '<path d="M495.9 166.6c3.2 8.7 .5 18.4-6.4 24.6l-43.3 39.4c1.1 8.3 1.7 16.8 1.7 25.4s-.6 17.1-1.7 25.4l43.3 39.4c6.9 6.2 9.6 15.9 6.4 24.6c-4.4 11.9-9.7 23.3-15.8 34.3l-4.7 8.1c-6.6 11-14 21.4-22.1 31.2c-5.9 7.2-15.7 9.6-24.5 6.8l-55.7-17.7c-13.4 10.3-28.2 18.9-44 25.4l-12.5 57.1c-2 9.1-9 16.3-18.2 17.8c-13.8 2.3-28 3.5-42.5 3.5s-28.7-1.2-42.5-3.5c-9.2-1.5-16.2-8.7-18.2-17.8l-12.5-57.1c-15.8-6.5-30.6-15.1-44-25.4L83.1 425.9c-8.8 2.8-18.6 .3-24.5-6.8c-8.1-9.8-15.5-20.2-22.1-31.2l-4.7-8.1c-6.1-11-11.4-22.4-15.8-34.3c-3.2-8.7-.5-18.4 6.4-24.6l43.3-39.4C64.6 273.1 64 264.6 64 256s.6-17.1 1.7-25.4L22.4 191.2c-6.9-6.2-9.6-15.9-6.4-24.6c4.4-11.9 9.7-23.3 15.8-34.3l4.7-8.1c6.6-11 14-21.4 22.1-31.2c5.9-7.2 15.7-9.6 24.5-6.8l55.7 17.7c13.4-10.3 28.2-18.9 44-25.4l12.5-57.1c2-9.1 9-16.3 18.2-17.8C227.3 1.2 241.5 0 256 0s28.7 1.2 42.5 3.5c9.2 1.5 16.2 8.7 18.2 17.8l12.5 57.1c15.8 6.5 30.6 15.1 44 25.4l55.7-17.7c8.8-2.8 18.6-.3 24.5 6.8c8.1 9.8 15.5 20.2 22.1 31.2l4.7 8.1c6.1 11 11.4 22.4 15.8 34.3zM256 336a80 80 0 1 0 0-160 80 80 0 1 0 0 160z"/>',
            'bell'            => '<path d="M224 0c-17.7 0-32 14.3-32 32V51.2C119 66 64 130.6 64 208v18.8c0 47-17.3 92.4-48.5 127.6l-7.4 8.3c-8.4 9.4-10.4 22.9-5.3 34.4S19.4 416 32 416H416c12.6 0 24-7.4 29.2-18.9s3.1-25-5.3-34.4l-7.4-8.3C401.3 319.2 384 273.9 384 226.8V208c0-77.4-55-142-128-156.8V32c0-17.7-14.3-32-32-32zm45.3 493.3c12-12 18.7-28.3 18.7-45.3H224 160c0 17 6.7 33.3 18.7 45.3s28.3 18.7 45.3 18.7s33.3-6.7 45.3-18.7z"/>',
            'envelope'        => '<path d="M48 64C21.5 64 0 85.5 0 112c0 15.1 7.1 29.3 19.2 38.4L236.8 313.6c11.4 8.5 27 8.5 38.4 0L492.8 150.4c12.1-9.1 19.2-23.3 19.2-38.4c0-26.5-21.5-48-48-48H48zM0 176V384c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V176L294.4 339.2c-22.8 17.1-54 17.1-76.8 0L0 176z"/>',
            'phone'           => '<path d="M164.9 24.6c-7.7-18.6-28-28.5-47.4-23.2l-88 24C12.1 30.2 0 46 0 64C0 311.4 200.6 512 448 512c18 0 33.8-12.1 38.6-29.5l24-88c5.3-19.4-4.6-39.7-23.2-47.4l-96-40c-16.3-6.8-35.2-2.1-46.3 11.6L304.7 368C234.3 334.7 177.3 277.7 144 207.3L193.3 167c13.7-11.2 18.4-30 11.6-46.3l-40-96z"/>',
            'laptop'          => '<path d="M128 32C92.7 32 64 60.7 64 96V352h512V96c0-35.3-28.7-64-64-64H128zM19.2 384C8.6 384 0 392.6 0 403.2C0 445.6 34.4 480 76.8 480H563.2c42.4 0 76.8-34.4 76.8-76.8c0-10.6-8.6-19.2-19.2-19.2H19.2z"/>',
            'camera'          => '<path d="M149.1 64.8L138.7 96H64C28.7 96 0 124.7 0 160V416c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V160c0-35.3-28.7-64-64-64H373.3L362.9 64.8C356.4 45.2 338.1 32 317.4 32H194.6c-20.7 0-39 13.2-45.5 32.8zM256 192a96 96 0 1 1 0 192 96 96 0 1 1 0-192z"/>',
            'film'            => '<path d="M0 96C0 60.7 28.7 32 64 32H448c35.3 0 64 28.7 64 64V416c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V96zM48 368v32c0 8.8 7.2 16 16 16H96c8.8 0 16-7.2 16-16V368c0-8.8-7.2-16-16-16H64c-8.8 0-16 7.2-16 16zm368-16c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V368c0-8.8-7.2-16-16-16H416zM48 240v32c0 8.8 7.2 16 16 16H96c8.8 0 16-7.2 16-16V240c0-8.8-7.2-16-16-16H64c-8.8 0-16 7.2-16 16zm368-16c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V240c0-8.8-7.2-16-16-16H416zM48 112v32c0 8.8 7.2 16 16 16H96c8.8 0 16-7.2 16-16V112c0-8.8-7.2-16-16-16H64c-8.8 0-16 7.2-16 16zM416 96c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V112c0-8.8-7.2-16-16-16H416zM160 128v256c0 17.7 14.3 32 32 32H320c17.7 0 32-14.3 32-32V128c0-17.7-14.3-32-32-32H192c-17.7 0-32 14.3-32 32z"/>',
            'music'           => '<path d="M499.1 6.3c8.1 6 12.9 15.6 12.9 25.7v72V368c0 44.2-43 80-96 80s-96-35.8-96-80s43-80 96-80c11.2 0 22 1.6 32 4.6V147L192 223.8V432c0 44.2-43 80-96 80s-96-35.8-96-80s43-80 96-80c11.2 0 22 1.6 32 4.6V200 128c0-14.1 9.3-26.6 22.8-30.7l320-96c9.7-2.9 20.2-1.1 28.3 5z"/>',
            'gamepad'         => '<path d="M192 64C86 64 0 150 0 256S86 448 192 448H448c106 0 192-86 192-192s-86-192-192-192H192zM496 168a40 40 0 1 1 0 80 40 40 0 1 1 0-80zM392 304a40 40 0 1 1 80 0 40 40 0 1 1 -80 0zM168 200c0-13.3 10.7-24 24-24s24 10.7 24 24v32h32c13.3 0 24 10.7 24 24s-10.7 24-24 24H216v32c0 13.3-10.7 24-24 24s-24-10.7-24-24V280H136c-13.3 0-24-10.7-24-24s10.7-24 24-24h32V200z"/>',
            'book'            => '<path d="M96 0C43 0 0 43 0 96V416c0 53 43 96 96 96H384h32c17.7 0 32-14.3 32-32s-14.3-32-32-32V384c17.7 0 32-14.3 32-32V32c0-17.7-14.3-32-32-32H384 96zm0 384H352v64H96c-17.7 0-32-14.3-32-32s14.3-32 32-32zm32-240c0-8.8 7.2-16 16-16H336c8.8 0 16 7.2 16 16s-7.2 16-16 16H144c-8.8 0-16-7.2-16-16zm16 48H336c8.8 0 16 7.2 16 16s-7.2 16-16 16H144c-8.8 0-16-7.2-16-16s7.2-16 16-16z"/>',
            'briefcase'       => '<path d="M184 48H328c4.4 0 8 3.6 8 8V96H176V56c0-4.4 3.6-8 8-8zm-56 8V96H64C28.7 96 0 124.7 0 160V416c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V160c0-35.3-28.7-64-64-64H384V56c0-30.9-25.1-56-56-56H184c-30.9 0-56 25.1-56 56z"/>',
            'building'        => '<path d="M48 0C21.5 0 0 21.5 0 48V464c0 26.5 21.5 48 48 48h96V432c0-26.5 21.5-48 48-48s48 21.5 48 48v80h96c26.5 0 48-21.5 48-48V48c0-26.5-21.5-48-48-48H48zM64 240c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16V240zm112-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H176c-8.8 0-16-7.2-16-16V240c0-8.8 7.2-16 16-16zm80 16c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H272c-8.8 0-16-7.2-16-16V240zM80 96h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16V112c0-8.8 7.2-16 16-16zm80 16c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H176c-8.8 0-16-7.2-16-16V112zm112-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H272c-8.8 0-16-7.2-16-16V112c0-8.8 7.2-16 16-16z"/>',
            'map-marker'      => '<path d="M215.7 499.2C267 435 384 279.4 384 192C384 86 298 0 192 0S0 86 0 192c0 87.4 117 243 168.3 307.2c12.3 15.3 35.1 15.3 47.4 0zM192 128a64 64 0 1 1 0 128 64 64 0 1 1 0-128z"/>',
            'comment'         => '<path d="M123.6 391.3c12.9-9.4 29.6-11.8 44.6-6.4c26.5 9.6 56.2 15.1 87.8 15.1c124.7 0 208-80.5 208-160s-83.3-160-208-160S48 160.5 48 240c0 32 12.4 62.8 35.7 89.2c8.6 9.7 12.8 22.5 11.8 35.5c-1.4 18.1-5.7 34.7-11.3 49.4c17-7.9 31.1-16.7 39.4-22.7zM21.2 431.9c1.8-2.7 3.5-5.4 5.1-8.1c10-16.6 19.5-38.4 21.4-62.9C17.7 326.8 0 285.1 0 240C0 125.1 114.6 32 256 32s256 93.1 256 208s-114.6 208-256 208c-37.1 0-72.3-6.4-104.1-17.9c-11.9 8.7-31.3 20.6-54.3 30.6c-15.1 6.6-32.3 12.6-50.1 16.1c-.8 .2-1.6 .3-2.4 .5c-4.4 .8-8.7 1.5-13.2 1.9c-.2 0-.5 .1-.7 .1c-5.1 .5-10.2 .8-15.3 .8c-6.5 0-12.3-3.9-14.8-9.9c-2.5-6-1.1-12.8 3.4-17.4c4.1-4.2 7.8-8.7 11.3-13.5c1.7-2.3 3.3-4.6 4.8-6.9c.1-.2 .2-.3 .3-.5z"/>',
            'share'           => '<path d="M307 34.8c-11.5 5.1-19 16.6-19 29.2v64H176C78.8 128 0 206.8 0 304C0 417.3 81.5 467.9 100.2 478.1c2.5 1.4 5.3 1.9 8.1 1.9c10.9 0 19.7-8.9 19.7-19.7c0-7.5-4.3-14.4-9.8-19.5C108.8 431.9 96 414.4 96 384c0-53 43-96 96-96h96v64c0 12.6 7.4 24.1 19 29.2s25 3 34.4-5.4l160-144c6.7-6.1 10.6-14.7 10.6-23.8s-3.8-17.7-10.6-23.8l-160-144c-9.4-8.5-22.9-10.6-34.4-5.4z"/>',
            'flag'            => '<path d="M64 32C64 14.3 49.7 0 32 0S0 14.3 0 32V64 368 480c0 17.7 14.3 32 32 32s32-14.3 32-32V352l64.3-16.1c41.1-10.3 84.6-5.5 122.5 13.4c44.2 22.1 95.5 24.8 141.7 7.4l34.7-13c12.5-4.7 20.8-16.6 20.8-30V66.1c0-23-24.2-38-44.8-27.7l-9.6 4.8c-46.3 23.2-100.8 23.2-147.1 0c-35.1-17.6-75.4-22-113.5-12.5L64 48V32z"/>',
            'lock'            => '<path d="M144 144v48H304V144c0-44.2-35.8-80-80-80s-80 35.8-80 80zM80 192V144C80 64.5 144.5 0 224 0s144 64.5 144 144v48h16c35.3 0 64 28.7 64 64V448c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V256c0-35.3 28.7-64 64-64H80z"/>',
            'unlock'          => '<path d="M144 144c0-44.2 35.8-80 80-80c31.9 0 59.4 18.6 72.3 45.7c7.6 16 26.7 22.8 42.6 15.2s22.8-26.7 15.2-42.6C331 33.7 281.5 0 224 0C144.5 0 80 64.5 80 144v48H64c-35.3 0-64 28.7-64 64V448c0 35.3 28.7 64 64 64H384c35.3 0 64-28.7 64-64V256c0-35.3-28.7-64-64-64H144V144z"/>',
            'key'             => '<path d="M336 352c97.2 0 176-78.8 176-176S433.2 0 336 0S160 78.8 160 176c0 18.7 2.9 36.8 8.3 53.7L7 391c-4.5 4.5-7 10.6-7 17v80c0 13.3 10.7 24 24 24h80c13.3 0 24-10.7 24-24V448h40c13.3 0 24-10.7 24-24V384h40c6.4 0 12.5-2.5 17-7l33.3-33.3c16.9 5.4 35 8.3 53.7 8.3zM376 96a40 40 0 1 1 0 80 40 40 0 1 1 0-80z"/>',
            'users'           => '<path d="M144 0a80 80 0 1 1 0 160A80 80 0 1 1 144 0zM512 0a80 80 0 1 1 0 160A80 80 0 1 1 512 0zM0 298.7C0 239.8 47.8 192 106.7 192h42.7c15.9 0 31 3.5 44.6 9.7c-1.3 7.2-1.9 14.7-1.9 22.3c0 38.2 16.8 72.5 43.3 96c-.2 0-.4 0-.7 0H21.3C9.6 320 0 310.4 0 298.7zM405.3 320c-.2 0-.4 0-.7 0c26.6-23.5 43.3-57.8 43.3-96c0-7.6-.7-15-1.9-22.3c13.6-6.3 28.7-9.7 44.6-9.7h42.7C592.2 192 640 239.8 640 298.7c0 11.8-9.6 21.3-21.3 21.3H405.3zM224 224a96 96 0 1 1 192 0 96 96 0 1 1 -192 0zM128 485.3C128 411.7 187.7 352 261.3 352H378.7C452.3 352 512 411.7 512 485.3c0 14.7-11.9 26.7-26.7 26.7H154.7c-14.7 0-26.7-11.9-26.7-26.7z"/>',
            'user-circle'     => '<path d="M399 384.2C376.9 345.8 335.4 320 288 320H224c-47.4 0-88.9 25.8-111 64.2c35.2 39.2 86.2 63.8 143 63.8s107.8-24.7 143-63.8zM0 256a256 256 0 1 1 512 0A256 256 0 1 1 0 256zm256 16a72 72 0 1 0 0-144 72 72 0 1 0 0 144z"/>',
            'user-friends'    => '<path d="M96 128a128 128 0 1 1 256 0A128 128 0 1 1 96 128zM0 482.3C0 383.8 79.8 304 178.3 304h91.4C368.2 304 448 383.8 448 482.3c0 16.4-13.3 29.7-29.7 29.7H29.7C13.3 512 0 498.7 0 482.3zM609.3 512H471.4c5.4-9.4 8.6-20.3 8.6-32v-8c0-60.7-27.1-115.2-69.8-151.8c2.4-.1 4.7-.2 7.1-.2h61.4C567.8 320 640 392.2 640 481.3c0 17-13.8 30.7-30.7 30.7zM432 256c-31 0-59-12.6-79.3-32.9C372.4 196.5 384 163.6 384 128c0-26.8-6.6-52.1-18.3-74.3C384.3 40.1 407.2 32 432 32c61.9 0 112 50.1 112 112s-50.1 112-112 112z"/>',
            'user-plus'       => '<path d="M96 128a128 128 0 1 1 256 0A128 128 0 1 1 96 128zM0 482.3C0 383.8 79.8 304 178.3 304h91.4C368.2 304 448 383.8 448 482.3c0 16.4-13.3 29.7-29.7 29.7H29.7C13.3 512 0 498.7 0 482.3zM504 312V248c0-13.3-10.7-24-24-24s-24 10.7-24 24v64H392c-13.3 0-24 10.7-24 24s10.7 24 24 24h64v64c0 13.3 10.7 24 24 24s24-10.7 24-24V400h64c13.3 0 24-10.7 24-24s-10.7-24-24-24H504z"/>',
            'user-times'      => '<path d="M96 128a128 128 0 1 1 256 0A128 128 0 1 1 96 128zM0 482.3C0 383.8 79.8 304 178.3 304h91.4C368.2 304 448 383.8 448 482.3c0 16.4-13.3 29.7-29.7 29.7H29.7C13.3 512 0 498.7 0 482.3zM471 143c9.4-9.4 24.6-9.4 33.9 0l47 47 47-47c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9l-47 47 47 47c9.4 9.4 9.4 24.6 0 33.9s-24.6 9.4-33.9 0l-47-47-47 47c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9l47-47-47-47c-9.4-9.4-9.4-24.6 0-33.9z"/>',
            'user-secret'     => '<path d="M224 16c-6.7 0-10.8-2.8-15.5-6.1C201.9 5.4 194 0 176 0c-30.5 0-52 43.7-66 89.4C62.7 98.1 32 112.2 32 128c0 14.3 25 27.1 64.6 35.9c-.4 4-.6 8-.6 12.1c0 17 3.3 33.2 9.3 48H45.4C38 224 32 230 32 237.4c0 1.7 .3 3.4 1 5l38.8 96.9C75.9 353.4 91.2 364 107.8 364H340.2c16.6 0 31.9-10.6 36-26.7L415 240.4c.6-1.6 1-3.3 1-5c0-7.4-6-13.4-13.4-13.4H342.7c6-14.8 9.3-31 9.3-48c0-4.1-.2-8.1-.6-12.1C391 155.1 416 142.3 416 128c0-15.8-30.7-29.9-78-38.6C324 43.7 302.5 0 272 0c-18 0-25.9 5.4-32.5 9.9c-4.7 3.3-8.8 6.1-15.5 6.1zm56 208H224V192h56v32zm-56 32H168c-8.8 0-16 7.2-16 16v16c0 8.8 7.2 16 16 16h56c8.8 0 16-7.2 16-16V272c0-8.8-7.2-16-16-16H416zM48 240v32c0 8.8 7.2 16 16 16H96c8.8 0 16-7.2 16-16V240c0-8.8-7.2-16-16-16H64c-8.8 0-16 7.2-16 16zm368-16c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V240c0-8.8-7.2-16-16-16H416zM48 112v32c0 8.8 7.2 16 16 16H96c8.8 0 16-7.2 16-16V112c0-8.8-7.2-16-16-16H64c-8.8 0-16 7.2-16 16zM416 96c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V112c0-8.8-7.2-16-16-16H416zM160 128v256c0 17.7 14.3 32 32 32H320c17.7 0 32-14.3 32-32V128c0-17.7-14.3-32-32-32H192c-17.7 0-32 14.3-32 32z"/>',
            'user-tie'        => '<path d="M224 0c70.7 0 128 57.3 128 128s-57.3 128-128 128s-128-57.3-128-128S153.3 0 224 0zM209.1 359.2l-18.6-31c-6.4-10.7 1.3-24.2 13.7-24.2H224h19.7c12.4 0 20.1 13.6 13.7 24.2l-18.6 31 33.4 123.9 36-146.9c2-8.1 9.8-13.4 17.9-11.3c70.1 17.6 121.9 81 121.9 156.4c0 17-13.8 30.7-30.7 30.7H285.5c-2.1 0-4-.4-5.8-1.1l.3 1.1H168c-2.1 0-4-.4-5.8-1.1l.3 1.1H30.7C13.8 512 0 498.2 0 481.3c0-75.5 51.9-138.9 121.9-156.4c8.1-2 15.9 3.3 17.9 11.3l36 146.9 33.4-123.9z"/>'
        ];
    }

    /**
     * Render a language option for the language switcher
     *
     * @param int $mediaId Media ID for this language option
     * @param string $langCode Language code
     * @param bool $isChecked Whether this option is currently selected
     * @return void Outputs HTML directly
     */
    public static function renderLanguageOption($mediaId, $langCode, $isChecked = false)
    {
        $langInfo = self::getLanguageInfo($langCode);
        $checkedAttr = $isChecked ? 'data-checked' : '';
        ?>
        <media-radio class="fluent-player-language-radio" value="<?php echo esc_attr($mediaId); ?>" data-lang="<?php echo esc_attr($langCode); ?>" <?php echo \esc_attr($checkedAttr); ?>>
            <span class="fluent-player-language-flag-option"><?php echo esc_html($langInfo['flag']); ?></span>
            <span class="fluent-player-language-radio-label"><?php echo esc_html($langInfo['name']); ?></span>
            <media-icon class="fluent-player-language-radio-icon" type="check"></media-icon>
        </media-radio>
        <?php
    }

    /**
     * Sanitize text that may contain HTML from FluentCRM smartcode parser.
     * Only allows <a> and <br> tags. Returns esc_html() for plain text.
     *
     * @param string $text
     * @return string
     */
    public static function escSmartcodeHtml($text)
    {
        if ($text === strip_tags($text)) {
            return esc_html($text);
        }

        $sanitized = wp_kses($text, [
            'a'  => [
                'href'   => [],
                'class'  => [],
                'target' => [],
                'rel'    => [],
            ],
            'br' => [],
        ]);

        return self::ensureLinksNewTab($sanitized);
    }

    /**
     * Add target="_blank" and rel="noopener noreferrer" to links missing a target attribute.
     * Controlled by the fluent_player/link_new_tab filter (default: true).
     *
     * @param string $html
     * @return string
     */
    public static function ensureLinksNewTab($html)
    {
        if (!apply_filters('fluent_player/link_new_tab', true)) {
            return $html;
        }

        return preg_replace_callback('/<a\s([^>]*)>/i', function ($matches) {
            $attrs = $matches[1];
            if (stripos($attrs, 'target=') === false) {
                $attrs .= ' target="_blank"';
            }
            if (stripos($attrs, 'rel=') === false) {
                $attrs .= ' rel="noopener noreferrer"';
            }
            return '<a ' . $attrs . '>';
        }, $html);
    }
}
