<?php

namespace FluentPlayer\App\PageBuilders\Bricks;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\PageBuilders\AbstractPageBuilder;
use FluentPlayer\App\Services\PageBuilderService;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;
use FluentPlayer\App\Helpers\Helper;

/**
 * Bricks builder adapter. Registers native Bricks elements — a "Fluent Player" media element
 * (free) and a playlist element (Pro-only) — whose render() delegates to the shared
 * AbstractPageBuilder helpers, giving a Bricks placement full parity with the block/shortcode.
 *
 * Registration is wired at plugin load via registerEarly(), not register(): our elements
 * `extends \Bricks\Element`, which Bricks loads on init:10, so they must register at init:11
 * — but the init:20 boot flow that calls register() has already missed that window.
 */
class BricksPageBuilder extends AbstractPageBuilder
{
    public function getKey(): string
    {
        return 'bricks';
    }

    public function isActive(): bool
    {
        return defined('BRICKS_VERSION') && class_exists('\\Bricks\\Elements');
    }

    public function register(): void
    {
        // Wiring is done in registerEarly() at plugin load (see the class docblock).
    }

    /**
     * Wire the Bricks hooks at plugin load. Elements register on init:11, after Bricks'
     * Elements::init_elements (init:10) loads the base class they extend. Safe to call
     * unconditionally — the callbacks bail when Bricks is absent.
     */
    public static function registerEarly(): void
    {
        add_action('init', [self::class, 'registerElements'], 11);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueBuilderAssets']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueuePanelAssets']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueCanvasAssets']);

        // Give the 'fluent-player' element category a label in the builder panel; Bricks
        // resolves category slugs against this i18n map (an unmapped slug shows raw).
        add_filter('bricks/builder/i18n', [self::class, 'registerCategoryLabel']);
    }

    /**
     * Absolute paths of the Bricks element classes to register. The playlist element is
     * Pro-only (its block + REST routes live in Pro), like the Elementor/Divi playlist.
     *
     * @return string[]
     */
    public static function elementFiles(): array
    {
        $files = [__DIR__ . '/FluentPlayerMediaElement.php'];

        if (Helper::hasPro()) {
            $files[] = __DIR__ . '/FluentPlayerPlaylistElement.php';
        }

        return $files;
    }

    /** Register each element file with Bricks. No-op if the registry is unavailable. */
    public static function registerElements(): void
    {
        if (!class_exists('\\Bricks\\Elements')) {
            return;
        }

        foreach (self::elementFiles() as $file) {
            \Bricks\Elements::register_element($file);
        }
    }

    /**
     * Label the "Fluent Player" element category via Bricks' builder i18n map (slug => label).
     *
     * @param array $i18n
     * @return array
     */
    public static function registerCategoryLabel($i18n): array
    {
        if (is_array($i18n)) {
            $i18n['fluent-player'] = __('Fluent Player', 'fluent-player');
        }

        return $i18n;
    }

    /**
     * Whether the current request is the Bricks builder. Fail-closed (default FALSE unless
     * positively in the builder) so the enqueue hooks — wired for every install — never load
     * the runtime on a non-Bricks front-end page. Filterable for tests / detection changes.
     */
    public static function isBuilderRequest(): bool
    {
        $inBuilder = (function_exists('bricks_is_builder') && bricks_is_builder())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return (bool) apply_filters('fluent_player/bricks/is_builder_request', $inBuilder);
    }

    /**
     * The Bricks builder shell (the window with the settings panel) — NOT the canvas iframe.
     * wp_enqueue_scripts fires in both documents, so each bundle must gate on the one it can
     * operate in: the panel bridge belongs here, the runtime/canvas bridge in the iframe.
     */
    public static function isBuilderMain(): bool
    {
        $isMain = function_exists('bricks_is_builder_main') && bricks_is_builder_main();

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return (bool) apply_filters('fluent_player/bricks/is_builder_main', $isMain);
    }

    /** The Bricks builder canvas iframe (where the player renders) — NOT the panel shell. */
    public static function isBuilderIframe(): bool
    {
        $isIframe = function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe();

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return (bool) apply_filters('fluent_player/bricks/is_builder_iframe', $isIframe);
    }

    /**
     * Enqueue the player runtime (+ playlist runtime when Pro) into the Bricks canvas iframe
     * so the AJAX-rendered element hydrates. Iframe-only — the panel shell has no player.
     */
    public static function enqueueBuilderAssets(): void
    {
        if (!self::isBuilderIframe()) {
            return;
        }

        Enqueue::script('fluent_player', 'js/fluent-player.js', [], FLUENT_PLAYER_VERSION, true);
        Enqueue::style('fluent_player_css', 'scss/public/fluent-player.scss', [], FLUENT_PLAYER_VERSION);

        // Flag the builder canvas so the runtime installs its re-hydration bridge (Bricks
        // re-renders the node after the initial scan, else the loader stays over a ready video).
        wp_add_inline_script('fluent_player', 'window.fluentPlayerBuilderContext=true;', 'before');

        // Pro's block enqueues the playlist runtime at render time, which doesn't reliably
        // print inside the builder iframe — load it here under the canonical handles (deduped).
        if (Helper::hasPro()) {
            Enqueue::script('fluent_playlist', 'js/fluent-playlist.js', [], FLUENT_PLAYER_VERSION, true);
            Enqueue::style('fluent_playlist_css', 'scss/public/fluent-playlist.scss', [], FLUENT_PLAYER_VERSION);
        }
    }

    /**
     * Mount the shared MediaSettingsApp into the Bricks sidebar so an element edits its media
     * like the block. Mirrors the Elementor bridge (same React panels + blockVars payload).
     */
    public static function enqueuePanelAssets(): void
    {
        // Main builder window only (the panel bridge can't operate in the canvas iframe, where
        // it would poll for a non-existent nested iframe). Capability floor too, since this
        // localizes blockVars (media list + REST nonce).
        if (!self::isBuilderMain() || !current_user_can(Helper::authoringCapability())) {
            return;
        }

        // MediaUpload (subtitle/thumbnail) is only a Slot until media-utils fills it AND the WP
        // media library is loaded — neither present in a builder by default, so enqueue media.
        wp_enqueue_media();

        Enqueue::script(
            'fluent-player-bricks-panel',
            'blocks/media/bricks/panel.jsx',
            ['react', 'react-dom', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'wp-block-editor', 'wp-media-utils'],
            FLUENT_PLAYER_VERSION,
            true
        );

        // The reused panels are styled for the Gutenberg sidebar; load the block's editor CSS.
        wp_enqueue_style('wp-components');
        Enqueue::style('fluent-player-block-editor-style', 'scss/fluent-player-block.scss', ['wp-components'], FLUENT_PLAYER_VERSION);
        wp_enqueue_style(
            'fluent-player-bricks-panel-style',
            FLUENT_PLAYER_URL . 'assets/editor.css',
            ['fluent-player-block-editor-style'],
            FLUENT_PLAYER_VERSION
        );

        // Hide the id/tag text controls from the panel (they are the on-canvas picker's write
        // conduit), and collapse the now-empty controls container — scoped via :has() to only our
        // element panels — so the sidebar shows just the settings sub-panels with no gap.
        wp_add_inline_style(
            'fluent-player-bricks-panel-style',
            '#bricks-panel-element [data-controlkey="mediaId"],'
            . '#bricks-panel-element [data-controlkey="playlistId"],'
            . '#bricks-panel-element [data-controlkey="fp_source_type"],'
            . '#bricks-panel-element [data-controlkey="fp_tags"],'
            . '#bricks-panel-element [data-controlkey="fp_query_limit"],'
            . '#bricks-panel-element [data-controlkey="fp_query_orderby"],'
            . '#bricks-panel-element [data-controlkey="fp_query_order"]{display:none !important;}'
            . '#bricks-panel-element .bricks-panel-controls:has([data-controlkey="mediaId"]),'
            . '#bricks-panel-element .bricks-panel-controls:has([data-controlkey="playlistId"]){margin-bottom:0 !important;padding-bottom:0 !important;}'
        );

        // Localize the panel payload ONCE on this handle (the canvas bundle depends on it).
        wp_localize_script('fluent-player-bricks-panel', 'fluentPlayerBlockVars', PageBuilderService::blockVars());

        // Playlist settings-panel bridge (Pro-only): creates the sidebar host the canvas
        // PlaylistEditor portals its BlockInspector into. Pure DOM wiring — no blockVars here.
        if (Helper::hasPro()) {
            Enqueue::script(
                'fluent-player-bricks-playlist-panel',
                'blocks/media/bricks/playlistPanel.jsx',
                ['wp-dom-ready'],
                FLUENT_PLAYER_VERSION,
                true
            );
            // The portaled inspector's layout cards / selectors need the playlist block editor CSS.
            Enqueue::style('fluent-player-playlist-block-editor-style', 'scss/fluent-player-playlist-block.scss', ['fluent-player-block-editor-style'], FLUENT_PLAYER_VERSION);
        }
    }

    /**
     * Mount the shared timed-content editor below each element on the Bricks canvas. Needs the
     * full WP block-editor stack; the canvas bundle self-gates to where it finds a player.
     */
    public static function enqueueCanvasAssets(): void
    {
        // Canvas iframe only (this is where the player + timed content render). Capability
        // floor too, since it localizes blockVars.
        if (!self::isBuilderIframe() || !current_user_can(Helper::authoringCapability())) {
            return;
        }

        Enqueue::script(
            'fluent-player-bricks-canvas',
            'blocks/media/bricks/canvas.jsx',
            [
                'react', 'react-dom', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data',
                'wp-blocks', 'wp-block-library', 'wp-block-editor', 'wp-format-library',
            ],
            FLUENT_PLAYER_VERSION,
            true
        );

        // Block-editor chrome so the mounted timed-content editor is styled + interactive.
        wp_enqueue_style('wp-block-editor');
        wp_enqueue_style('wp-edit-blocks');
        Enqueue::style('fluent-player-block-editor-style', 'scss/fluent-player-block.scss', ['wp-components'], FLUENT_PLAYER_VERSION);

        // The panel bundle lives in the OTHER document (the builder shell), so localize the
        // blockVars payload on the canvas bundle too — each window needs its own copy.
        wp_localize_script('fluent-player-bricks-canvas', 'fluentPlayerBlockVars', PageBuilderService::blockVars());

        // Playlist authoring is Pro-only — mount the playlist block editor below a playlist
        // element on the canvas (parallels the media timed-content editor). The playlist block +
        // REST routes live in Pro; skip when Pro is inactive (the element isn't registered then).
        if (Helper::hasPro()) {
            // Playlist block editor JS (registers fluent-player/playlist + edit.jsx) so the mounted
            // PlaylistEditor can create the block; a free build Pro normally enqueues only on the
            // playlist CPT screen. Data comes from Pro's REST routes.
            Enqueue::script(
                'fluent-player-playlist-block-editor',
                'blocks/playlist/fluent-playlist-block.jsx',
                ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'jquery-ui-sortable'],
                FLUENT_PLAYER_VERSION,
                true
            );
            Enqueue::style('fluent-player-playlist-block-editor-style', 'scss/fluent-player-playlist-block.scss', [], FLUENT_PLAYER_VERSION);

            Enqueue::script(
                'fluent-player-bricks-playlist-canvas',
                'blocks/media/bricks/playlistCanvas.jsx',
                [
                    'react', 'react-dom', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data',
                    'wp-blocks', 'wp-block-library', 'wp-block-editor', 'wp-format-library',
                    'fluent-player-playlist-block-editor',
                    // Depend on the media canvas handle so its fluentPlayerBlockVars localization
                    // prints first — the payload is serialized ONCE per window, not re-attached here.
                    'fluent-player-bricks-canvas',
                ],
                FLUENT_PLAYER_VERSION,
                true
            );
            // No re-localize: 'fluent-player-bricks-canvas' (a dependency, same iframe) sets the global.
        }
    }
}
