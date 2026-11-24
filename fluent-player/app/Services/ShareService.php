<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Hooks\Handlers\FluentPlayerMediaCPT;
use FluentPlayer\App\Models\Media;
use FluentPlayer\Framework\Support\Arr;

/**
 * Sharing is opt-in per video ("Allow sharing & embedding"). A shared video can
 * be embedded on other sites through <permalink>/embed/. The build* helpers are
 * shared with Pro playlists, which decide sharing themselves.
 */
class ShareService
{
    public static function isShareable($post)
    {
        return $post instanceof \WP_Post
            && $post->post_type === 'fluent_player_media'
            && $post->post_status === 'publish'
            && $post->post_password === ''
            && FluentPlayerMediaCPT::pagesEnabled()
            && !empty(Arr::get((array) Media::getMediaSettings($post), 'share_enabled'));
    }

    /**
     * The link, title and embed code for a video, or null when it is not shared.
     */
    public static function getShareData($mediaId)
    {
        // get_post(0) would return the current global post.
        $post = absint($mediaId) ? get_post(absint($mediaId)) : null;
        if (!self::isShareable($post)) {
            return null;
        }

        $title = MediaService::parseSmartcodes($post->post_title, ['media' => $post]);

        return self::buildShareData($post, $title, self::aspectRatio($post));
    }

    /**
     * Open Graph and Twitter Card tags for a shared video page.
     */
    public static function getSocialTags($post)
    {
        if (!self::isShareable($post)) {
            return [];
        }

        $settings = (array) Media::getMediaSettings($post);
        $title = MediaService::parseSmartcodes($post->post_title, ['media' => $post]);
        $description = (string) Arr::get($settings, 'description', '') ?: (string) $post->post_excerpt;

        return self::buildSocialTags($post, $title, $description, self::posterFor($settings), self::aspectRatio($post));
    }

    /**
     * The link, title and embed code for a post its caller has decided is shared.
     *
     * @param array $ratio [width, height]
     */
    public static function buildShareData(\WP_Post $post, $title, array $ratio = [16, 9])
    {
        $title = self::titleOrFallback($title);

        // The block comments paste this as a Custom HTML block, not a sandboxed Embed
        // block the player cannot run in. Core's wp-embed grows .wp-embedded-content
        // iframes to the reported height but strips their style, so sizing lives on the div.
        $embed = sprintf(
            "<!-- wp:html -->\n" . '<div style="max-width: 640px;"><iframe class="wp-embedded-content" src="%1$s" title="%2$s" width="100%%" height="%3$d" frameborder="0" allow="autoplay; fullscreen; picture-in-picture; encrypted-media; clipboard-write" allowfullscreen></iframe></div><script src="%4$s" defer></script>' . "\n<!-- /wp:html -->",
            esc_url(get_post_embed_url($post)),
            esc_attr($title),
            round(640 * $ratio[1] / $ratio[0]),
            esc_url(includes_url('js/wp-embed.min.js'))
        );

        return [
            'url'   => get_permalink($post),
            'title' => $title,
            'embed' => $embed,
        ];
    }

    /**
     * Open Graph and Twitter Card tags for a post its caller has decided is shared,
     * empty ones dropped. The X player card plays the /embed/ page inside the post.
     *
     * @param array $ratio [width, height]
     */
    public static function buildSocialTags(\WP_Post $post, $title, $description, $image, array $ratio = [16, 9])
    {
        $title = self::titleOrFallback($title);

        return array_filter([
            'og:type'               => 'video.other',
            'og:title'              => $title,
            'og:url'                => get_permalink($post),
            'og:description'        => trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(wp_html_excerpt((string) $description, 200)))),
            'og:image'              => $image,
            'twitter:card'          => 'player',
            'twitter:title'         => $title,
            'twitter:image'         => $image,
            'twitter:player'        => get_post_embed_url($post),
            'twitter:player:width'  => '640',
            'twitter:player:height' => (string) round(640 * $ratio[1] / $ratio[0]),
        ]);
    }

    /**
     * Untitled videos still need an accessible name for the iframe and a card title.
     */
    private static function titleOrFallback($title)
    {
        $title = trim((string) $title);

        /* translators: %s: site name */
        return $title !== '' ? $title : sprintf(__('Video on %s', 'fluent-player'), get_bloginfo('name'));
    }

    /**
     * SEO plugins print their own preview tags.
     */
    public static function hasSeoPlugin()
    {
        return defined('WPSEO_VERSION') || class_exists('RankMath') || defined('AIOSEO_VERSION');
    }

    /**
     * A video's poster, falling back to the YouTube thumbnail.
     */
    public static function posterFor(array $settings)
    {
        $image = (string) Arr::get($settings, 'posterSrc', '');
        if (!$image) {
            $youtubeId = Helper::extractYouTubeVideoId((string) Arr::get($settings, 'src', ''));
            $image = $youtubeId ? Helper::getYouTubeFallbackPosterUrl($youtubeId) : '';
        }

        return $image;
    }

    /**
     * The video's aspect ratio as [width, height], 16:9 when unset or malformed.
     */
    private static function aspectRatio($post)
    {
        $ratio = (string) Arr::get((array) Media::getMediaSettings($post), 'aspectRatio', '16:9');
        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $ratio, $matches) && (int) $matches[1] > 0 && (int) $matches[2] > 0) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [16, 9];
    }
}
