<?php

namespace FluentPlayer\App\PageBuilders\Elementor;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\PageBuilders\AbstractPageBuilder;

/**
 * Elementor widget that places a Fluent Player playlist. A searchable playlist
 * picker rendered through the shared AbstractPageBuilder::renderPlaylist() (the
 * fluent-player/playlist block via do_blocks — Pro renders it). Authoring happens
 * in the builder canvas (the mounted playlist block editor), not the panel, so the
 * only control here is the picker. Only loaded when Elementor is active.
 */
class ElementorPlaylistWidget extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'fluent_player_playlist';
    }

    public function get_title()
    {
        return __('Fluent Player Playlist', 'fluent-player');
    }

    public function get_icon()
    {
        // eicon-playlist does not exist in Elementor's icon set (renders blank);
        // eicon-video-playlist is the built-in playlist glyph.
        return 'eicon-video-playlist';
    }

    public function get_categories()
    {
        return ['fluent-player'];
    }

    public function get_keywords()
    {
        return ['fluent', 'player', 'playlist', 'video', 'audio', 'media'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('fp_content', [
            'label' => __('Playlist', 'fluent-player'),
        ]);

        // The canvas picker (playlistCanvas.jsx) writes these via $e.run; HIDDEN keeps them
        // persisted but out of the panel (no sidebar picker).
        $this->add_control('playlist_id', [
            'type'    => \Elementor\Controls_Manager::HIDDEN,
            'default' => '',
        ]);

        // Tag-source mode: when fp_source_type === 'tags' the widget renders a dynamic
        // tag-based playlist instead of a saved one. fp_tags is a comma-separated slug list.
        $this->add_control('fp_source_type', [
            'type'    => \Elementor\Controls_Manager::HIDDEN,
            'default' => 'playlist',
        ]);
        $this->add_control('fp_tags', [
            'type'    => \Elementor\Controls_Manager::HIDDEN,
            'default' => '',
        ]);
        $this->add_control('fp_query_limit', [
            'type'    => \Elementor\Controls_Manager::HIDDEN,
            'default' => 20,
        ]);
        $this->add_control('fp_query_orderby', [
            'type'    => \Elementor\Controls_Manager::HIDDEN,
            'default' => 'date',
        ]);
        $this->add_control('fp_query_order', [
            'type'    => \Elementor\Controls_Manager::HIDDEN,
            'default' => 'DESC',
        ]);

        // Mount point in the panel for the playlist block's settings (BlockInspector),
        // portaled here from the canvas editor by PlaylistEditor.jsx.
        $this->add_control('fp_playlist_settings', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => '<div class="fp-el-playlist-settings-mount fp-el-host fluent-player-block-panel"></div>',
        ]);

        $this->end_controls_section();
    }

    protected function render()
    {
        $settings   = $this->get_settings_for_display();
        $sourceType = isset($settings['fp_source_type']) ? sanitize_key($settings['fp_source_type']) : 'playlist';
        $playlistId = isset($settings['playlist_id']) ? absint($settings['playlist_id']) : 0;

        if ($sourceType === 'tags') {
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($settings['fp_tags'] ?? '')))));
            if (empty($tags)) {
                $this->renderEditorPlaceholder();
                return;
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block markup escaped in renderPlaylist
            echo AbstractPageBuilder::renderPlaylist(0, [
                'sourceType' => 'tags',
                'tags'       => $tags,
                'query'      => [
                    'limit'   => $settings['fp_query_limit'] ?? 20,
                    'orderby' => $settings['fp_query_orderby'] ?? 'date',
                    'order'   => $settings['fp_query_order'] ?? 'DESC',
                ],
            ]);
            return;
        }

        if (!$playlistId) {
            $this->renderEditorPlaceholder();
            return;
        }

        // Shared server-built playlist markup (Pro's fluent-player/playlist block).
        echo AbstractPageBuilder::renderPlaylist($playlistId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * The canvas picker replaces this in edit mode; on the front end an unconfigured
     * widget renders nothing.
     */
    private function renderEditorPlaceholder()
    {
        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            printf(
                '<div class="fluent-player-page-builder-placeholder" style="padding:24px;text-align:center;border:1px dashed #c3c4c7;border-radius:8px;color:#50575e;">%s</div>',
                esc_html__('Select a Fluent Player playlist to display.', 'fluent-player')
            );
        }
    }
}
