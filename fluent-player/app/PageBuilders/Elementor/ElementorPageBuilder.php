<?php

namespace FluentPlayer\App\PageBuilders\Elementor;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\PageBuilders\AbstractPageBuilder;
use FluentPlayer\App\Services\PageBuilderService;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;
use FluentPlayer\App\Helpers\Helper;

/**
 * Elementor adapter. Registers a "Fluent Player" widget category and the media
 * widget, and makes the player runtime available inside the editor preview
 * iframe. The widget itself (ElementorMediaWidget) extends \Elementor\Widget_Base,
 * so it is only required inside the widgets/register hook — which fires only when
 * Elementor is active — to avoid touching Elementor classes when it is absent.
 */
class ElementorPageBuilder extends AbstractPageBuilder
{
    public function getKey(): string
    {
        return 'elementor';
    }

    public function isActive(): bool
    {
        return defined('ELEMENTOR_VERSION') || did_action('elementor/loaded');
    }

    public function register(): void
    {
        add_action('elementor/elements/categories_registered', [$this, 'registerCategory']);
        add_action('elementor/widgets/register', [$this, 'registerWidget']);
        add_action('elementor/preview/enqueue_scripts', [$this, 'enqueuePreviewAssets']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueueEditorAssets']);
    }

    /**
     * Localize the shared fluentPlayerBlockVars (media list, defaults, REST config)
     * onto a handle. It is a large payload, so attach it ONCE per rendering window: the
     * editor window (settings handle) and the preview iframe (canvas handle). Other iframe
     * scripts read the global the canvas handle already set — they must NOT re-localize it,
     * or the payload is serialized twice in the same window.
     */
    private function localizeBlockVars(string $handle): void
    {
        wp_localize_script($handle, 'fluentPlayerBlockVars', PageBuilderService::blockVars());
    }

    /**
     * Load the shared media-settings app into the Elementor editor panel so the
     * widget's settings edit the selected media exactly like the block. Localises
     * the SAME fluentPlayerBlockVars the block panels rely on.
     */
    public function enqueueEditorAssets(): void
    {
        // MediaUpload (subtitle / thumbnail / logo uploads) needs the WP media library +
        // @wordpress/media-utils to fill its Slot, or the buttons render empty.
        wp_enqueue_media();

        Enqueue::script(
            'fluent-player-elementor-settings',
            'blocks/media/elementor/editor.jsx',
            ['react', 'react-dom', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'wp-block-editor', 'wp-media-utils'],
            FLUENT_PLAYER_VERSION,
            true
        );

        // The reused panels are styled for the Gutenberg (light) sidebar; the block's
        // compiled editor CSS carries the Elementor dark-panel theming (.fp-el-host
        // scope — an Elementor-only host class so it never leaks into Divi's panel),
        // so no inline surface CSS is needed here.
        wp_enqueue_style('wp-components');
        Enqueue::style(
            'fluent-player-block-editor-style',
            'scss/fluent-player-block.scss',
            ['wp-components'],
            FLUENT_PLAYER_VERSION
        );
        wp_enqueue_style(
            'fluent-player-elementor-settings-style',
            FLUENT_PLAYER_URL . 'assets/editor.css',
            ['fluent-player-block-editor-style'],
            FLUENT_PLAYER_VERSION
        );

        // The playlist widget's settings (BlockInspector) render in this panel,
        // portaled from the canvas editor — so the playlist block editor stylesheet
        // (layout cards, selectors, labels) must load in the top window here, even
        // though the block JS runs in the preview iframe.
        Enqueue::style('fluent-player-playlist-block-editor-style', 'scss/fluent-player-playlist-block.scss', ['fluent-player-block-editor-style'], FLUENT_PLAYER_VERSION);

        $this->localizeBlockVars('fluent-player-elementor-settings');
    }

    /**
     * Style handles the timed-content authoring editor needs in the preview iframe.
     *
     * The preview IS the user's page, so anything enqueued here cascades over their own
     * widgets. `wp-edit-blocks` reaches into wp-admin (wp-base-styles ->
     * wp-admin/css/common.css) and its `body { font-size: 13px }` shrank every widget while
     * building, with the front end left correct. wp-block-editor styles the editor without
     * leaving it. (Divi's builder had the same leak — see DiviPageBuilder::canvasEditorStyleDeps.)
     *
     * @return string[]
     */
    public static function canvasEditorStyleHandles(): array
    {
        return ['wp-block-editor'];
    }

    /** @param \Elementor\Elements_Manager $manager */
    public function registerCategory($manager): void
    {
        $manager->add_category('fluent-player', [
            'title' => __('Fluent Player', 'fluent-player'),
            'icon'  => 'eicon-play',
        ]);
    }

    /** @param \Elementor\Widgets_Manager $widgetsManager */
    public function registerWidget($widgetsManager): void
    {
        require_once __DIR__ . '/ElementorMediaWidget.php';
        $widgetsManager->register(new ElementorMediaWidget());

        // The playlist is a Pro feature — only expose its widget when Pro is active, so it
        // never appears in Elementor's widget panel on a free-only site.
        if (Helper::hasPro()) {
            require_once __DIR__ . '/ElementorPlaylistWidget.php';
            $widgetsManager->register(new ElementorPlaylistWidget());
        }
    }

    /**
     * Elementor renders widgets inside a preview iframe; enqueue the base player
     * runtime there so the widget's MediaRenderer output initialises in-editor.
     * (Per-instance media styles are enqueued by MediaRenderer at render time.)
     */
    public function enqueuePreviewAssets(): void
    {
        Enqueue::script('fluent_player', 'js/fluent-player.js', [], FLUENT_PLAYER_VERSION, true);
        Enqueue::style('fluent_player_css', 'scss/public/fluent-player.scss', [], FLUENT_PLAYER_VERSION);

        // The canvas media picker (shown on an empty widget) opens the WP media library for its
        // Media Library / Audio buttons, so the preview iframe needs it — the editor-window
        // enqueue doesn't reach the preview document.
        wp_enqueue_media();

        // Playlist widget runtime + styles — Pro-only, so skip when Pro is inactive (the
        // playlist widget itself isn't registered then). Pro's block enqueues these at render
        // time, which doesn't reliably print inside Elementor's preview iframe — so load them
        // here under the canonical handles (WP dedupes with Pro's enqueue).
        if (Helper::hasPro()) {
            Enqueue::script('fluent_playlist', 'js/fluent-playlist.js', [], FLUENT_PLAYER_VERSION, true);
            Enqueue::style('fluent_playlist_css', 'scss/public/fluent-playlist.scss', [], FLUENT_PLAYER_VERSION);
        }

        // Timed-content authoring is a real Gutenberg block editor mounted in the
        // preview canvas (reused from the Divi integration), so the preview iframe
        // needs the WP block-editor stack. Editor-only — elementor/preview never
        // fires on a front-end page load, so visitors never receive this.
        Enqueue::script(
            'fluent-player-elementor-canvas',
            'blocks/media/elementor/canvas.jsx',
            [
                'react', 'react-dom', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data',
                'wp-blocks', 'wp-block-library', 'wp-block-editor', 'wp-format-library', 'wp-media-utils',
            ],
            FLUENT_PLAYER_VERSION,
            true
        );

        // Block-editor chrome (block layout, appender, RichText) so the mounted
        // editor is styled + interactive, plus the block-panel styles the reused
        // timed-content area/section UI expects.
        foreach (self::canvasEditorStyleHandles() as $handle) {
            wp_enqueue_style($handle);
        }
        Enqueue::style('fluent-player-block-editor-style', 'scss/fluent-player-block.scss', ['wp-components'], FLUENT_PLAYER_VERSION);

        // The reused editor + saver + the Pro authoring gate read fluentPlayerBlockVars.
        $this->localizeBlockVars('fluent-player-elementor-canvas');

        // Playlist authoring is Pro-only — skip its canvas assets when Pro is inactive (the
        // playlist widget isn't registered then, so nothing mounts them).
        if (Helper::hasPro()) {
            // Mounts the standalone playlist block editor in the canvas. Load the playlist block
            // editor JS (registers fluent-player/playlist + edit.jsx, a free build Pro normally
            // enqueues only on the playlist CPT screen) + the canvas bridge. Data comes from Pro.
            Enqueue::script(
                'fluent-player-playlist-block-editor',
                'blocks/playlist/fluent-playlist-block.jsx',
                ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'jquery-ui-sortable'],
                FLUENT_PLAYER_VERSION,
                true
            );
            Enqueue::style('fluent-player-playlist-block-editor-style', 'scss/fluent-player-playlist-block.scss', [], FLUENT_PLAYER_VERSION);
            Enqueue::script(
                'fluent-player-elementor-playlist-canvas',
                'blocks/media/elementor/playlistCanvas.jsx',
                [
                    'react', 'react-dom', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data',
                    'wp-blocks', 'wp-block-library', 'wp-block-editor', 'wp-format-library',
                    'fluent-player-playlist-block-editor',
                    // Depend on the media canvas so its fluentPlayerBlockVars localisation prints
                    // first: the large payload is serialized ONCE per window (see below), not
                    // re-localized onto this handle too.
                    'fluent-player-elementor-canvas',
                ],
                FLUENT_PLAYER_VERSION,
                true
            );
            // No localizeBlockVars here — 'fluent-player-elementor-canvas' (a dependency, same
            // preview iframe) already sets the global. Re-attaching the heavy payload to this
            // handle would serialize it a second time in the same window.
        }
    }
}
