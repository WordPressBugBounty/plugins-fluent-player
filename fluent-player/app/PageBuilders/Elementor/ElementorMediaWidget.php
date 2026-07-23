<?php

namespace FluentPlayer\App\PageBuilders\Elementor;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\PageBuilders\AbstractPageBuilder;

/**
 * Elementor widget that places a Fluent Player media. Slice 1: a searchable
 * media picker rendered through the shared AbstractPageBuilder::renderPlayer()
 * (full parity with the block/shortcode). Per-widget setting overrides are a
 * later slice. Only loaded when Elementor is active (extends Widget_Base).
 */
class ElementorMediaWidget extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'fluent_player_media';
    }

    public function get_title()
    {
        return __('Fluent Player', 'fluent-player');
    }

    public function get_icon()
    {
        return 'eicon-play';
    }

    public function get_categories()
    {
        return ['fluent-player'];
    }

    public function get_keywords()
    {
        return ['fluent', 'player', 'video', 'audio', 'media'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('fp_content', [
            'label' => __('Media', 'fluent-player'),
        ]);

        // Media is chosen in the CANVAS via the media picker (resources/blocks/media/elementor/
        // canvas.jsx), not the sidebar. This stays a registered — but HIDDEN — setting so the
        // picker's write ($e.run document/elements/settings) persists and render() reads it.
        $this->add_control('media_id', [
            'type'    => \Elementor\Controls_Manager::HIDDEN,
            'default' => '',
        ]);

        // Mount point for the shared React settings app (the block's setting
        // panels, bound to the selected media). Populated by the editor bridge
        // (resources/blocks/media/elementor/editor.jsx) on panel open.
        $this->add_control('fp_settings_mount', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => '<div class="fp-el-settings-mount fp-el-host"></div>',
        ]);

        $this->end_controls_section();
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $mediaId  = isset($settings['media_id']) ? absint($settings['media_id']) : 0;

        if (!$mediaId) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                printf(
                    '<div class="fluent-player-page-builder-placeholder" style="padding:24px;text-align:center;border:1px dashed #c3c4c7;border-radius:8px;color:#50575e;">%s</div>',
                    esc_html__('Select a Fluent Player media to display.', 'fluent-player')
                );
            }
            return;
        }

        // MediaRenderer returns server-built, escaped player markup.
        echo AbstractPageBuilder::renderPlayer($mediaId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
