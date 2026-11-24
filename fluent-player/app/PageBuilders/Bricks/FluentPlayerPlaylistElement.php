<?php

namespace FluentPlayer\App\PageBuilders\Bricks;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\PageBuilders\AbstractPageBuilder;

/**
 * Bricks "Fluent Player Playlist" element (Pro-only) — a searchable playlist picker whose
 * render() delegates to AbstractPageBuilder::renderPlaylist() (Pro's playlist block via
 * do_blocks). Registered only when Pro is active (see BricksPageBuilder::elementFiles).
 */
class FluentPlayerPlaylistElement extends \Bricks\Element
{
    public $category = 'fluent-player';
    public $name     = 'fluent-player-playlist';
    public $icon     = 'ti-menu-alt';

    public function get_label()
    {
        return esc_html__('Fluent Player Playlist', 'fluent-player');
    }

    public function get_keywords()
    {
        return ['fluent', 'player', 'playlist', 'video', 'audio'];
    }

    public function set_controls()
    {
        // Playlist is chosen on the canvas (CanvasPlaylistPicker); the picker commits into these
        // hidden TEXT controls (Bricks' real change path). Hidden from the panel via BricksPageBuilder CSS.
        $this->controls['playlistId']       = ['tab' => 'content', 'label' => esc_html__('Playlist ID', 'fluent-player'), 'type' => 'text'];
        // Tag-source mode (the picker's "By Tags").
        $this->controls['fp_source_type']   = ['tab' => 'content', 'label' => esc_html__('Source type', 'fluent-player'), 'type' => 'text'];
        $this->controls['fp_tags']          = ['tab' => 'content', 'label' => esc_html__('Tags', 'fluent-player'), 'type' => 'text'];
        $this->controls['fp_query_limit']   = ['tab' => 'content', 'label' => esc_html__('Query limit', 'fluent-player'), 'type' => 'text'];
        $this->controls['fp_query_orderby'] = ['tab' => 'content', 'label' => esc_html__('Query orderby', 'fluent-player'), 'type' => 'text'];
        $this->controls['fp_query_order']   = ['tab' => 'content', 'label' => esc_html__('Query order', 'fluent-player'), 'type' => 'text'];
    }

    public function render()
    {
        $sourceType = isset($this->settings['fp_source_type']) ? sanitize_key($this->settings['fp_source_type']) : 'playlist';

        if ($sourceType === 'tags') {
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($this->settings['fp_tags'] ?? '')))));
            if (!empty($tags)) {
                $inner = AbstractPageBuilder::renderPlaylist(0, [
                    'sourceType' => 'tags',
                    'tags'       => $tags,
                    'query'      => [
                        'limit'   => $this->settings['fp_query_limit'] ?? 20,
                        'orderby' => $this->settings['fp_query_orderby'] ?? 'date',
                        'order'   => $this->settings['fp_query_order'] ?? 'DESC',
                    ],
                ]);
                echo "<div {$this->render_attributes('_root')}>" . $inner . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
            }
        }

        $playlistId = isset($this->settings['playlistId']) ? absint($this->settings['playlistId']) : 0;

        if (!$playlistId) {
            // Render our OWN container (keeps the brxe-fluent-player-playlist class) so the canvas bridge
            // finds the empty element and mounts the picker — Bricks' generic placeholder drops the class.
            echo "<div {$this->render_attributes('_root')}>" // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                . AbstractPageBuilder::emptyPlaceholder(esc_html__('Select a Fluent Player playlist to display.', 'fluent-player'))
                . '</div>';
            return;
        }

        // renderPlaylist() renders Pro's playlist block, visibility-gated.
        echo "<div {$this->render_attributes('_root')}>" . AbstractPageBuilder::renderPlaylist($playlistId) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
