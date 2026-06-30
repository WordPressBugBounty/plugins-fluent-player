<?php

namespace FluentPlayer\App\Hooks\Handlers;
if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\DynamicMediaSourceResolver;
use FluentPlayer\App\Services\MediaRenderer;
use FluentPlayer\Framework\Support\Arr;

class MediaShortcodeHandler
{
    /**
     * Handle the shortcode.
     *
     * Attributes:
     * - id             (required) Saved media item — supplies player chrome.
     * - source_url     (optional) Explicit URL.
     * - source_meta    (optional) Meta key read from the post being rendered.
     *                  Power users override the lookup target via the
     *                  `fluent_player/dynamic_source_post_id` filter.
     * - source_poster  (optional) Explicit poster URL.
     *
     * Source precedence: source_url → source_meta on current post → saved media.
     *
     * @param array  $atts    Shortcode attributes.
     * @param string $content Shortcode content (unused).
     * @return string HTML output.
     */
    public function handle($atts, $content = '')
    {
        $defaults = [
            'id'            => null,
            'source_url'    => '',
            'source_meta'   => '',
            'source_poster' => '',
        ];
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $defaults = apply_filters('fluent_player/media_shortcode_defaults', $defaults, $atts);
        $atts = shortcode_atts($defaults, $atts);

        $mediaId = absint(Arr::get($atts, 'id'));
        $media = $mediaId ? Media::findVisible($mediaId) : null;
        if (!$media) {
            return $mediaId ? Media::getAccessDeniedCurtain($mediaId) : '';
        }

        $overrides = DynamicMediaSourceResolver::resolve(
            esc_url_raw(Arr::get($atts, 'source_url', '')),
            sanitize_text_field(Arr::get($atts, 'source_meta', '')),
            esc_url_raw(Arr::get($atts, 'source_poster', ''))
        );

        // Saved-media path: do_blocks renders the media block + timed-content
        // InnerBlocks. Override path skips do_blocks (timed content is tied to
        // specific timecodes and doesn't apply to a substituted video).
        if (!$overrides) {
            return MediaRenderer::renderFromPost($mediaId);
        }
        return MediaRenderer::render($mediaId, '', $overrides);
    }
}
