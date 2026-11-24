<?php

namespace FluentPlayer\App\PageBuilders\Bricks;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\PageBuilders\AbstractPageBuilder;

/**
 * Bricks "Fluent Player" media element — a searchable media picker whose render() delegates
 * to AbstractPageBuilder::renderPlayer() for full block/shortcode parity. Settings are edited
 * on the media itself (the source-of-truth model Elementor/Divi also use).
 */
class FluentPlayerMediaElement extends \Bricks\Element
{
    public $category = 'fluent-player';
    public $name     = 'fluent-player-media';
    public $icon     = 'ti-control-play';

    public function get_label()
    {
        return esc_html__('Fluent Player', 'fluent-player');
    }

    public function get_keywords()
    {
        return ['fluent', 'player', 'video', 'audio', 'media'];
    }

    public function set_controls()
    {
        // Media is chosen on the canvas (CanvasMediaPicker); the picker commits into this hidden
        // TEXT control (Bricks' real change path). Hidden from the panel via BricksPageBuilder CSS.
        $this->controls['mediaId'] = [
            'tab'   => 'content',
            'label' => esc_html__('Media ID', 'fluent-player'),
            'type'  => 'text',
        ];
    }

    public function render()
    {
        $mediaId = isset($this->settings['mediaId']) ? absint($this->settings['mediaId']) : 0;

        if (!$mediaId) {
            // Render our OWN container (keeps the brxe-fluent-player-media class) so the canvas bridge
            // finds the empty element and mounts the picker — Bricks' generic placeholder drops the class.
            echo "<div {$this->render_attributes('_root')}>" // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                . AbstractPageBuilder::emptyPlaceholder(esc_html__('Select a Fluent Player media to display.', 'fluent-player'))
                . '</div>';
            return;
        }

        // In the builder render SELF-CONTAINED (config inlined as a <script>): Bricks' AJAX
        // re-render never prints wp_localize_script config, so the canvas bridge re-executes
        // the inline config to hydrate. The front end prints scripts the normal way.
        $inBuilder = BricksPageBuilder::isBuilderRequest();
        $html = $inBuilder
            ? AbstractPageBuilder::renderPlayerSelfContained($mediaId)
            : AbstractPageBuilder::renderPlayer($mediaId);

        echo "<div {$this->render_attributes('_root')}>" . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
