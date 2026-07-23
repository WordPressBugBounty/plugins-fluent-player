<?php

namespace FluentPlayer\App\PageBuilders;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\MediaRenderer;

/**
 * Base for page-builder integrations (Elementor, Bricks, ...).
 *
 * Mirrors the AbstractIntegration pattern: one shared surface, thin per-builder
 * adapters. Each concrete builder is registered with PageBuilderService and only
 * booted when its builder plugin is active.
 *
 * The shared, host-agnostic render capability lives here as a static helper so the
 * builder's native widget class (which must extend the builder's own base, e.g.
 * \Elementor\Widget_Base) can call it without multiple inheritance:
 *  - renderPlayer(): renders a media through the canonical MediaRenderer, so a
 *    widget inherits FULL parity (settings, overlays, layers, signed URLs,
 *    script/style enqueue) with the block and shortcode.
 *
 * Pickers no longer preload an options list — both builders search the REST media/
 * playlist endpoints on demand (Elementor SELECT2 AJAX; Divi custom async field).
 */
abstract class AbstractPageBuilder
{
    /** Stable identifier for this builder (e.g. 'elementor', 'bricks'). */
    abstract public function getKey(): string;

    /** Whether the builder plugin is present/active on this site. */
    abstract public function isActive(): bool;

    /** Wire the builder's widget/category registration hooks. Called once when active. */
    abstract public function register(): void;

    /**
     * Shared dashed-border "nothing selected" placeholder used by every builder's
     * empty state (Elementor widgets + Divi modules), so the markup + inline style
     * live in one place instead of being copy-pasted per adapter.
     */
    public static function emptyPlaceholder(string $text): string
    {
        return sprintf(
            '<div class="fluent-player-pb-preview--empty" style="padding:24px;text-align:center;border:1px dashed #c3c4c7;border-radius:8px;color:#50575e;">%s</div>',
            esc_html($text)
        );
    }

    /**
     * Render a media's player. One render path shared by every builder, giving
     * full parity with the block/shortcode — including timed content and other
     * InnerBlocks (rendered via do_blocks when no overrides are supplied).
     * $overrides merge over the media's saved settings (the opt-in per-widget
     * customisation), and force the player-only path since do_blocks renders the
     * saved media as-is.
     *
     * @param int|string $mediaId
     * @param array       $overrides
     * @return string
     */
    public static function renderPlayer($mediaId, array $overrides = []): string
    {
        $mediaId = absint($mediaId);
        if (!$mediaId) {
            return '';
        }

        // Mirror the block/shortcode access gate: MediaRenderer::render() only
        // gates password protection, not post status, so a widget pointing at a
        // draft/pending media would otherwise leak it to visitors who cannot
        // read it. Show the same access-denied curtain those paths use.
        if (!Media::findVisible($mediaId)) {
            return Media::getAccessDeniedCurtain($mediaId);
        }

        return (string) MediaRenderer::renderForPageBuilder($mediaId, 'fluent-player-page-builder', $overrides);
    }

    /**
     * Render a playlist for a page-builder placement. The fluent-player/playlist
     * block + its render_callback live in Pro, so this renders the same block the
     * CPT/shortcode use via do_blocks() — Pro renders it, layouts and all. Without
     * Pro the block is unregistered and do_blocks yields nothing (empty widget).
     * Visibility mirrors renderPlayer / Media::findVisible exactly: published and
     * private playlists render for anyone (private = embeddable, standalone page
     * hidden); other statuses render only for a user with the `read_post` capability
     * for it. A non-playlist id is rejected before render, so an arbitrary post id
     * can never be handed to the playlist block.
     *
     * A tag source (['sourceType' => 'tags', 'tags' => [...], 'query' => [...]]) renders a
     * dynamic tag playlist via the same block (Pro's PlaylistBlock::renderTagSource), so the
     * id/visibility checks are skipped.
     *
     * @param int|string $playlistId
     * @param array      $source ['sourceType' => 'tags', 'tags' => string[], 'query' => array]
     * @return string
     */
    public static function renderPlaylist($playlistId, array $source = []): string
    {
        $sourceType = isset($source['sourceType']) ? sanitize_key($source['sourceType']) : 'playlist';

        if ($sourceType === 'tags') {
            return self::renderPlaylistByTags($source);
        }

        $playlistId = absint($playlistId);
        if (!$playlistId) {
            return '';
        }

        if (get_post_type($playlistId) !== 'fluent_playlist') {
            return '';
        }

        if (!in_array(get_post_status($playlistId), ['publish', 'private'], true)
            && !current_user_can('read_post', $playlistId)
        ) {
            return '';
        }

        $block = sprintf('<!-- wp:fluent-player/playlist {"playlistId":%d} /-->', $playlistId);
        $inner = do_blocks($block);
        if (trim($inner) === '') {
            return '';
        }

        return '<div class="fluent-player-page-builder" data-playlist-id="' . esc_attr($playlistId) . '">' . $inner . '</div>';
    }

    /**
     * Emit the fluent-player/playlist block in tag-source mode. Returns '' when no tags are
     * given so an unconfigured tag widget stays empty (parity with the id path).
     *
     * @param array $source ['tags' => string[], 'query' => ['limit'|'orderby'|'order']]
     * @return string
     */
    private static function renderPlaylistByTags(array $source): string
    {
        $attrs = self::buildPlaylistTagAttrs($source);
        if (empty($attrs['tags'])) {
            return '';
        }

        $block = '<!-- wp:fluent-player/playlist ' . wp_json_encode($attrs) . ' /-->';
        $inner = do_blocks($block);
        if (trim($inner) === '') {
            return '';
        }

        return '<div class="fluent-player-page-builder" data-playlist-source="tags">' . $inner . '</div>';
    }

    /**
     * Sanitise a tag source into the fluent-player/playlist block's tag attributes. Pure
     * (no rendering) so the clamping/whitelisting is unit-testable; Pro re-sanitises too.
     *
     * @param array $source ['tags' => mixed, 'query' => array]
     * @return array{sourceType:string, tags:string[], query:array{limit:int,orderby:string,order:string}}
     */
    public static function buildPlaylistTagAttrs(array $source): array
    {
        $tags  = array_values(array_filter(array_map('sanitize_text_field', (array) ($source['tags'] ?? []))));
        $query = is_array($source['query'] ?? null) ? $source['query'] : [];

        return [
            'sourceType' => 'tags',
            'tags'       => $tags,
            'query'      => [
                'limit'   => min(max(absint($query['limit'] ?? 20), 1), 100),
                'orderby' => sanitize_key($query['orderby'] ?? 'date'),
                'order'   => strtoupper((string) ($query['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
            ],
        ];
    }

    /**
     * Self-contained variant of renderPlayer() for editor previews fetched over
     * REST (e.g. the Divi 5 Visual Builder), where WordPress never prints the
     * enqueued scripts. Inlines the player's localized config so the runtime can
     * hydrate the injected markup. Same visibility gate as renderPlayer().
     *
     * @param int|string $mediaId
     * @param array       $overrides
     * @return string
     */
    public static function renderPlayerSelfContained($mediaId, array $overrides = []): string
    {
        $mediaId = absint($mediaId);
        if (!$mediaId) {
            return '';
        }

        if (!Media::findVisible($mediaId)) {
            return Media::getAccessDeniedCurtain($mediaId);
        }

        return (string) MediaRenderer::renderSelfContained($mediaId, 'fluent-player-page-builder', $overrides);
    }
}
