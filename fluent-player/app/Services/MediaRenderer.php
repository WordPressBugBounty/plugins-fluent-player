<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;
use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\Translations\TransStrings;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;
use FluentPlayer\Framework\Support\Arr;

class MediaRenderer
{
    private static $preconnectAdded = false;
    private static $instanceCounter = 0;
    private static $globalsLocalized = false;

    /**
     * Render a media post through the block pipeline.
     * Processes the post's block content via do_blocks(), so MediaBlock::render()
     * fires and handles timed content InnerBlocks.
     * Falls back to render() if the post has no block content.
     *
     * Used by the shortcode handler and dedicated player page.
     *
     * @param int $mediaId The media post ID.
     * @return string Full rendered output including player + timed content.
     */
    public static function renderFromPost($mediaId)
    {
        $mediaId = absint($mediaId);
        if (!$mediaId) {
            return '';
        }

        $post = get_post($mediaId);
        if (!$post) {
            return '';
        }

        $blockContent = trim($post->post_content);
        if (!empty($blockContent) && function_exists('do_blocks')) {
            return do_blocks($blockContent);
        }

        $media = Media::find($mediaId);
        return Media::getStatusNotice($media) . self::render($mediaId);
    }

    /**
     * Render a media player by ID.
     * Returns only the player (no timed content). Called by MediaBlock::render()
     * for the actual player output.
     *
     * @param int    $mediaId      The media post ID.
     * @param string $extraClasses Extra CSS classes for the wrapper div.
     * @param array  $overrides    Optional in-memory settings overrides for this
     *                             render (`src`, `provider`, `posterSrc`).
     *                             Stored post meta is not mutated.
     * @return string Rendered player HTML wrapped in .fp-media-block, or empty on failure.
     */
    public static function render($mediaId, $extraClasses = '', $overrides = [])
    {
        $mediaId = absint($mediaId);
        if (!$mediaId) {
            return '';
        }

        $media = Media::find($mediaId);
        if (!$media) {
            return '';
        }

        if (!empty($overrides) && is_array($overrides)) {
            $currentSettings = (array) $media->settings;
            // Provider switch invalidates Mux/Bunny subfields from the saved
            // media — strip so they don't leak into localized JS.
            if (
                !empty($overrides['provider'])
                && isset($currentSettings['provider'])
                && $overrides['provider'] !== $currentSettings['provider']
            ) {
                unset($currentSettings['mux'], $currentSettings['bunny'], $currentSettings['bunny_storage']);
            }
            $media->settings = array_merge($currentSettings, $overrides);
        }

        do_action('fluent_player/before_render_media', $media);

        $mediaData = MediaService::prepareMediaForFrontend($media, 'shortcode');

        // Enqueue scripts and styles
        Enqueue::script(
            'fluent_player',
            'js/fluent-player.js',
            [],
            FLUENT_PLAYER_VERSION,
            true
        );

        Helper::enqueueTimedContentScript();

        Enqueue::style(
            'fluent_player_css',
            'scss/public/fluent-player.scss',
            [],
            FLUENT_PLAYER_VERSION
        );

        self::addResourceHints($mediaData['media']->settings);

        Helper::enqueuePlayerStyles($mediaData['media']->ID, $mediaData['media']->settings, $mediaData['default_settings']);

        self::$instanceCounter++;
        $instance_id = $mediaData['media']->ID . '_' . self::$instanceCounter;
        $media_var_name = 'fluent_player_' . $instance_id;

        // Allow pro plugin to inject signed URLs, DRM tokens, analytics keys
        // Must run BEFORE wp_localize_script to prevent unsigned URLs leaking to page JS
        $playerSettings = apply_filters('fluent_player/player_settings', $mediaData['media']->settings);
        $mediaData['media']->settings = $playerSettings;
        $mediaData['analytics_nonce'] = wp_create_nonce('fluent_player_track_event:' . $mediaData['media']->ID);

        $localizedMediaData = $mediaData;
        $localizedMediaData['media'] = self::serializeMediaForScript($mediaData['media']);

        wp_localize_script('fluent_player', $media_var_name, $localizedMediaData);

        // Global plugin config is identical across renders — localize once per page.
        if (!self::$globalsLocalized) {
            self::$globalsLocalized = true;

            $settings = Helper::getSettings();

            $globalPlayerSettings = [
                'ajax_url'        => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce('fluent_player_frontend'),
                'serverLang'      => $mediaData['serverLang'],
                'has_pro'         => Helper::hasPro(),
                'trans'           => TransStrings::getFrontendStrings(),
                'resume_playback' => defined('FLUENT_PLAYER_PRO_VERSION') && (bool) Arr::get($settings, 'general.resume_playback', false) === true,
            ];

            if (Helper::hasPro()) {
                $globalPlayerSettings['analytics'] = Arr::get($settings, 'analytics', []);
                $globalPlayerSettings['google_analytics'] = Arr::get($settings, 'google_analytics', []);
            }

            $youtubeSettings = Arr::get($settings, 'youtube', []);
            $globalPlayerSettings['youtube'] = [
                'privacy_mode'          => (bool) Arr::get($youtubeSettings, 'privacy_mode', false),
                'show_subscribe_button' => (bool) Arr::get($youtubeSettings, 'show_subscribe_button', false),
            ];

            $performanceSettings = Arr::get($settings, 'performance', []);
            $globalPlayerSettings['dynamic_load_js'] = (bool) Arr::get($performanceSettings, 'dynamic_load_js', false);

            wp_localize_script('fluent_player', 'fluent_player', $globalPlayerSettings);
        }

        $app = App::make();
        $playerHtml = $app->make('view')->make('player', [
            'media_id'         => $mediaData['media']->ID,
            'instance_id'      => $instance_id,
            'media_var_name'   => $media_var_name,
            'settings'         => $playerSettings,
            'default_settings' => $mediaData['default_settings'],
        ]);

        $classes = 'fp-media-block';
        if ($extraClasses) {
            $classes .= ' ' . $extraClasses;
        }

        return '<div class="' . esc_attr($classes) . '" data-media-id="' . esc_attr($mediaId) . '">'
             . $playerHtml
             . '</div>';
    }

    private static function addResourceHints($settings)
    {
        if (self::$preconnectAdded) {
            return;
        }

        self::$preconnectAdded = true;

        $domains = [];
        $provider = Arr::get($settings, 'provider', '');
        $src = Arr::get($settings, 'src', '');

        if ($provider === 'youtube' || strpos($src, 'youtube.com') !== false || strpos($src, 'youtu.be') !== false) {
            $domains = ['https://www.youtube.com', 'https://i.ytimg.com'];
        } elseif ($provider === 'vimeo' || strpos($src, 'vimeo.com') !== false) {
            $domains = ['https://player.vimeo.com', 'https://i.vimeocdn.com'];
        } elseif ($provider === 'bunny' || $provider === 'bunny_storage') {
            $hint = $src;
            if (!$hint || strpos($hint, 'bunny/storage/stream') !== false) {
                $hint = Arr::get($settings, 'bunny_storage.url', '');
            }
            $parsed = $hint ? wp_parse_url($hint) : false;
            if (is_array($parsed) && !empty($parsed['host'])) {
                $scheme = !empty($parsed['scheme']) && is_string($parsed['scheme']) ? $parsed['scheme'] : 'https';
                $port = !empty($parsed['port']) ? ':' . $parsed['port'] : '';
                $domains = [$scheme . '://' . $parsed['host'] . $port];
            }
        } elseif (strpos($src, 'bunny.net') !== false || strpos($src, 'bunnycdn.com') !== false || strpos($src, 'b-cdn.net') !== false) {
            $parsed = wp_parse_url($src);
            if (is_array($parsed) && !empty($parsed['host'])) {
                $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
                $port = !empty($parsed['port']) ? ':' . $parsed['port'] : '';
                $domains = [$scheme . '://' . $parsed['host'] . $port];
            }
        } elseif ($provider === 'mux' || $provider === 'mux_stream' || strpos($src, 'stream.mux.com') !== false) {
            $domains = ['https://stream.mux.com', 'https://image.mux.com'];
        }

        if (empty($domains)) {
            return;
        }

        add_action('wp_head', function () use ($domains) {
            foreach ($domains as $domain) {
                $host = wp_parse_url($domain, PHP_URL_HOST);
                if ($host === false || $host === null || $host === '') {
                    continue;
                }
                echo '<link rel="dns-prefetch" href="//' . esc_attr($host) . '">' . "\n";
                echo '<link rel="preconnect" href="' . esc_url($domain) . '" crossorigin>' . "\n";
            }
        }, 1);
    }

    private static function serializeMediaForScript($media)
    {
        if (!is_object($media)) {
            return (array) $media;
        }

        $id = isset($media->ID) ? $media->ID : (isset($media->id) ? $media->id : null);
        $title = isset($media->post_title) ? $media->post_title : (isset($media->title) ? $media->title : '');

        return [
            'ID'          => $id,
            'id'          => isset($media->id) ? $media->id : $id,
            'post_title'  => $title,
            'title'       => isset($media->title) ? $media->title : $title,
            'post_status' => isset($media->post_status) ? $media->post_status : '',
            'settings'    => isset($media->settings) && is_array($media->settings) ? $media->settings : [],
        ];
    }
}
