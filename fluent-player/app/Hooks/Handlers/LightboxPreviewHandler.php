<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;
use FluentPlayer\App\Services\MediaRenderer;
use FluentPlayer\App\Services\MediaService;

/**
 * Editor-only player preview for the Lightbox block: a bare player page the block
 * editor loads in an iframe. It is served from a query arg on the front end — not
 * the /embed/ route — so it works for drafts, private and unshared media and
 * without dedicated player pages, gated by a nonce plus edit_post on the media.
 */
class LightboxPreviewHandler
{
    const QUERY_ARG = 'fluent_player_preview';
    const NONCE_ACTION = 'fluent_player_lightbox_preview';

    /**
     * @return void
     */
    public static function register()
    {
        add_action('template_redirect', [self::class, 'maybeRender'], 1);
        // The admin bar initialises on template_redirect before maybeRender runs.
        add_filter('show_admin_bar', [self::class, 'hideAdminBar']);
    }

    /**
     * @param bool $show
     * @return bool
     */
    public static function hideAdminBar($show)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only toggle; rendering is nonce-gated in maybeRender
        return isset($_GET[self::QUERY_ARG]) ? false : $show;
    }

    /**
     * Base preview URL; the editor appends media_id, _wpnonce and autoplay.
     *
     * @return string
     */
    public static function baseUrl()
    {
        return add_query_arg(self::QUERY_ARG, '1', home_url('/'));
    }

    /**
     * template_redirect callback.
     *
     * @return void
     */
    public static function maybeRender()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is verified below before anything renders
        if (!isset($_GET[self::QUERY_ARG])) {
            return;
        }

        $mediaId = isset($_GET['media_id']) ? absint(wp_unslash($_GET['media_id'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        $autoplay = isset($_GET['autoplay']) && sanitize_key(wp_unslash($_GET['autoplay'])) === '1';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!self::canPreview($mediaId, $nonce)) {
            wp_die(
                esc_html__('You are not allowed to preview this media.', 'fluent-player'),
                esc_html__('Preview unavailable', 'fluent-player'),
                ['response' => 403]
            );
        }

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');

        // The player never sees itself as visible inside an iframe, so "load when visible" would never load.
        add_filter('fluent_player/player_settings', [DedicatedPlayerPage::class, 'loadEagerly'], 99);
        // Mirror the block's toggle both ways, like the lightbox itself does.
        add_filter('fluent_player/player_settings', function ($settings) use ($autoplay) {
            return self::withAutoplay($settings, $autoplay);
        }, 99);
        // An editor previewing their own media is not a viewer.
        add_filter('fluent_player/global_vars', [self::class, 'disableTracking'], 99);

        $post = get_post($mediaId);
        App::make('view')->render('frontend.embed-page', [
            'title'   => MediaService::parseSmartcodes($post->post_title, ['media' => $post]),
            'content' => MediaRenderer::renderFromPost($mediaId),
        ]);
        exit();
    }

    /**
     * @param int    $mediaId
     * @param string $nonce
     * @return bool
     */
    public static function canPreview($mediaId, $nonce)
    {
        return $mediaId > 0
            && get_post_type($mediaId) === 'fluent_player_media'
            && wp_verify_nonce($nonce, self::NONCE_ACTION)
            && current_user_can('edit_post', $mediaId);
    }

    /**
     * @param array $settings
     * @param bool  $enabled
     * @return array
     */
    public static function withAutoplay($settings, $enabled)
    {
        if (is_array($settings)) {
            $settings['autoplay'] = $enabled;
            if (!$enabled) {
                $settings['mutedAutoplay'] = false;
            }
        }
        return $settings;
    }

    /**
     * @param array $vars The `fluent_player` frontend global.
     * @return array
     */
    public static function disableTracking($vars)
    {
        if (!is_array($vars)) {
            return $vars;
        }
        if (isset($vars['analytics']) && is_array($vars['analytics'])) {
            $vars['analytics']['enabled'] = false;
        }
        if (isset($vars['google_analytics']) && is_array($vars['google_analytics'])) {
            $vars['google_analytics']['enabled'] = false;
        }
        return $vars;
    }
}
