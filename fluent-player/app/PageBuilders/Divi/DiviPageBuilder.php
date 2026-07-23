<?php

namespace FluentPlayer\App\PageBuilders\Divi;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\PageBuilders\AbstractPageBuilder;
use FluentPlayer\App\Helpers\Helper;

/**
 * Divi 5 adapter. Registers a native Divi 5 "Fluent Player" module (PHP render
 * callback + a Visual Builder JS component) that renders the shared player markup.
 *
 * Divi 5 only — the Divi 4 Visual Builder cannot render a third-party module's
 * markup at all.
 */
class DiviPageBuilder extends AbstractPageBuilder
{
    /** Divi 5's native-module interface — present only when the Divi 5 builder is active. */
    private const MODULE_INTERFACE = 'ET\\Builder\\Framework\\DependencyManagement\\Interfaces\\DependencyInterface';

    /**
     * The package-build handles that MUST be emitted as ES modules (both bundles use
     * import.meta via Vidstack). Single source of truth: these exact strings are reused
     * as the register_package_build() names below, so a rename can't silently desync the
     * script_loader_tag allowlist from the registered builds.
     */
    private const BUILD_RUNTIME          = 'fluent-player-runtime';
    private const BUILD_VB               = 'fluent-player-divi-vb';
    private const BUILD_TIMED_CONTENT    = 'fluent-player-timed-content';
    private const BUILD_PLAYLIST_RUNTIME = 'fluent-player-playlist-runtime';
    private const BUILD_PLAYLIST_VB      = 'fluent-player-divi-playlist-vb';

    /** Guards against registering the VB package builds twice within one request. */
    private static $vbComponentRegistered = false;

    /** @var array<string,string> Per-request memo of built-asset mtimes, keyed by filename. */
    private static $mtimeCache = [];

    public function getKey(): string
    {
        return 'divi';
    }

    public function isActive(): bool
    {
        // Divi 5's native module API. (Divi 4 is intentionally unsupported.)
        return interface_exists(self::MODULE_INTERFACE);
    }

    /**
     * Whether the current request is the Divi 5 Visual Builder, where the builder
     * assets + picker data are actually needed. Filterable so it can be forced in
     * tests or adjusted if Divi's builder detection changes.
     */
    public static function isVisualBuilderRequest(): bool
    {
        $isVb = function_exists('et_core_is_fb_enabled') && et_core_is_fb_enabled();

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return (bool) apply_filters('fluent_player/divi/is_visual_builder_request', $isVb);
    }

    public function register(): void
    {
        // Registration is wired at plugin load via registerEarly() — Divi 5's
        // dependency-tree action fires on `init` priority 0, before the init:20 boot
        // flow that calls this method. Nothing to do here.
    }

    /**
     * Wire the Divi 5 registration. Its dependency-tree action fires on `init`
     * priority 0, before our init:20 boot flow, so this must be hooked at plugin load —
     * PageBuilderService invokes it during register(), not the init:20 boot flow. Safe
     * to call unconditionally — the actions only fire under Divi 5.
     */
    public static function registerEarly(): void
    {
        add_action('divi_module_library_modules_dependency_tree', [self::class, 'registerDiviModule']);

        // Register the VB package builds on Divi's sanctioned pre-enqueue action, which
        // fires exactly when Divi is about to consume the registered builds — immune to
        // enqueue-priority drift. Keep the wp_enqueue_scripts:1 path as a fallback for
        // any build where the sanctioned action is unavailable; a run-once guard in the
        // callback means only the first of the two to fire does the work.
        add_action('divi_visual_builder_assets_before_enqueue_scripts', [self::class, 'registerVisualBuilderComponent']);
        add_action('wp_enqueue_scripts', [self::class, 'registerVisualBuilderComponent'], 1);
        add_filter('script_loader_tag', [self::class, 'markBuilderScriptsAsModule'], 10, 2);
    }

    /**
     * Force our builder <script>s to type="module" — Divi enqueues package builds
     * as classic scripts, which breaks ES-module syntax (both bundles use
     * import.meta, via Vidstack). The didAction() check in the module registration
     * handles the deferred-module timing.
     */
    public static function markBuilderScriptsAsModule($tag, $handle): string
    {
        $moduleHandles = [
            self::BUILD_RUNTIME, self::BUILD_VB, self::BUILD_TIMED_CONTENT,
            self::BUILD_PLAYLIST_RUNTIME, self::BUILD_PLAYLIST_VB,
        ];

        if (!in_array($handle, $moduleHandles, true)) {
            return $tag;
        }

        // Drop any existing type attribute, then declare the module type.
        $tag = preg_replace('/\stype=([\'"]).*?\1/', '', $tag);

        return str_replace('<script ', '<script type="module" ', $tag);
    }

    /**
     * Register the Divi 5 Visual Builder component (JS) so the module appears in
     * the builder and renders the real player on the canvas. Only the app window
     * (the builder iframe) needs it. No-op outside Divi 5.
     */
    public static function registerVisualBuilderComponent(): void
    {
        // Wired to both the sanctioned Divi action and wp_enqueue_scripts:1 (see
        // registerEarly): register the builds only once per request.
        if (self::$vbComponentRegistered) {
            return;
        }

        // Both hooks fire on every front-end request under Divi 5, but the picker data
        // below (a media query + block editor vars) is only needed in the builder —
        // bail early on normal page loads so visitors never pay for it. Don't set the
        // run-once flag here: a later builder-context hook in the same request may pass.
        if (!self::isVisualBuilderRequest()) {
            return;
        }

        // Explicit Divi 5 gate — the documented signal that the D5 builder is enabled.
        // class_exists(PackageBuildManager) below is a proxy for this; this is the
        // sanctioned check (e.g. Divi ships the class but D5 is disabled via option).
        if (function_exists('et_builder_d5_enabled') && !et_builder_d5_enabled()) {
            return;
        }

        $manager = 'ET\\Builder\\VisualBuilder\\Assets\\PackageBuildManager';
        if (!class_exists($manager)) {
            return;
        }

        self::$vbComponentRegistered = true;

        // MediaUpload (subtitle / thumbnail / logo uploads) needs the WP media library +
        // @wordpress/media-utils to fill its Slot, or the buttons render empty in the VB.
        wp_enqueue_media();

        $vite      = '\\FluentPlayer\\App\\Utils\\Enqueuer\\Vite';
        $blockVars = \FluentPlayer\App\Services\PageBuilderService::blockVars();

        // The media/playlist VB module scripts share the same dependency set.
        $moduleDeps = [
            'react', 'jquery', 'wp-hooks', 'divi-module-library', 'divi-module', 'divi-rest',
            'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'wp-block-editor',
            // wp-blocks/wp-block-library register the canvas editor's blocks;
            // wp-format-library gives RichText its formatting toolbar.
            'wp-blocks', 'wp-block-library', 'wp-format-library',
            // wp-media-utils fills the MediaUpload Slot for the subtitle/thumbnail/logo uploads.
            'wp-media-utils',
        ];

        // Player runtime + base stylesheet so the injected player hydrates in the canvas.
        self::registerBuild($manager, self::BUILD_RUNTIME,
            ['src' => $vite::getStaticFilePath('js/fluent-player.js')],
            ['src' => $vite::getStaticFilePath('scss/public/fluent-player.scss')]);

        // Timed-content hydration runtime for the canvas; no-op when a media has none. Its
        // style depends on the block-editor stylesheets the canvas editor needs, kept
        // app-window only so they don't restyle the settings sidebar.
        self::registerBuild($manager, self::BUILD_TIMED_CONTENT,
            ['src' => $vite::getStaticFilePath('js/timed-content-frontend.js')],
            ['src' => $vite::getStaticFilePath('scss/public/timed-content.scss'), 'deps' => ['wp-block-editor', 'wp-edit-blocks', 'wp-block-library']]);

        // The Visual Builder module component (registers the module + renders on canvas).
        // Block-panel styles load in both windows; the raw block-editor stylesheets stay
        // app-window (timed-content build) so they don't restyle the sidebar.
        self::registerBuild($manager, self::BUILD_VB,
            [
                'src'  => $vite::getStaticFilePath('blocks/media/divi/index.jsx'),
                'deps' => $moduleDeps,
                // The picker searches the REST endpoint on demand (custom async field), so no
                // media list is serialized into the payload — only the shared editor vars.
                'data' => ['blockVars' => $blockVars],
            ],
            ['src' => $vite::getStaticFilePath('scss/fluent-player-block.scss'), 'deps' => ['wp-components'], 'top' => true]);

        // The playlist is Pro-only — skip its builds entirely when Pro is inactive so the
        // module never loads or appears in the builder (parallels the registerDiviModule gate).
        if (Helper::hasPro()) {
            // Playlist runtime + base stylesheet so the injected playlist hydrates in the canvas.
            self::registerBuild($manager, self::BUILD_PLAYLIST_RUNTIME,
                ['src' => $vite::getStaticFilePath('js/fluent-playlist.js')],
                ['src' => $vite::getStaticFilePath('scss/public/fluent-playlist.scss')]);

            // The playlist VB component (registers the module + renders on canvas + mounts the
            // sidebar settings field).
            self::registerBuild($manager, self::BUILD_PLAYLIST_VB,
                [
                    'src'  => $vite::getStaticFilePath('blocks/playlist/divi/index.jsx'),
                    'deps' => $moduleDeps,
                    // Async picker (see BUILD_VB) — no playlist list serialized into the payload.
                    'data' => ['blockVars' => $blockVars],
                ],
                ['src' => $vite::getStaticFilePath('scss/fluent-player-playlist-block.scss'), 'deps' => ['wp-components'], 'top' => true]);
        }
    }

    /**
     * Register one Divi 5 Visual-Builder package build (script + style, app-window). Collapses
     * the five near-identical register_package_build() arrays into one call so their shared
     * shape (version = mtime of the script, enqueue_app_window) lives in one place.
     *
     * @param string $manager PackageBuildManager class name.
     * @param array  $script  ['src' => string, 'deps' => string[], 'top' => bool, 'data' => array|null]
     * @param array  $style   ['src' => string, 'deps' => string[], 'top' => bool]
     */
    private static function registerBuild($manager, string $name, array $script, array $style): void
    {
        $scriptBuild = [
            'src'                => $script['src'],
            'deps'               => $script['deps'] ?? [],
            'enqueue_top_window' => $script['top'] ?? false,
            'enqueue_app_window' => true,
        ];
        if (!empty($script['data'])) {
            $scriptBuild['data_app_window'] = $script['data'];
        }

        $manager::register_package_build([
            'name'    => $name,
            'version' => self::assetVersion($script['src']),
            'script'  => $scriptBuild,
            'style'   => [
                'src'                => $style['src'],
                'deps'               => $style['deps'] ?? [],
                'enqueue_top_window' => $style['top'] ?? false,
                'enqueue_app_window' => true,
            ],
        ]);
    }

    /**
     * Cache-busting version for a built builder asset. Vite emits stable filenames, so
     * the mtime is appended to ?ver — otherwise a rebuild at the same plugin version
     * serves stale. (Dev mode already busts via ?t=.)
     */
    private static function assetVersion(string $url): string
    {
        $vite = '\\FluentPlayer\\App\\Utils\\Enqueuer\\Vite';
        if ($vite::isOnDevMode()) {
            return FLUENT_PLAYER_VERSION;
        }

        $file = basename((string) wp_parse_url($url, PHP_URL_PATH));
        if ($file === '') {
            return FLUENT_PLAYER_VERSION;
        }

        if (isset(self::$mtimeCache[$file])) {
            return self::$mtimeCache[$file];
        }

        $path    = FLUENT_PLAYER_DIR_PATH . 'assets/' . $file;
        $version = is_readable($path)
            ? FLUENT_PLAYER_VERSION . '.' . filemtime($path)
            : FLUENT_PLAYER_VERSION;

        self::$mtimeCache[$file] = $version;

        return $version;
    }

    /**
     * Add the native Divi 5 module to the builder's dependency tree.
     *
     * @param mixed $dependencyTree ET\Builder\Framework\DependencyManagement\DependencyTree
     */
    public static function registerDiviModule($dependencyTree): void
    {
        // Loaded only here because the classes implement a Divi-5-only interface,
        // and this runs only when Divi 5 fires the action.
        require_once __DIR__ . '/FluentPlayerModule.php';
        $dependencyTree->add_dependency(new FluentPlayerModule());

        // The playlist is a Pro feature (its block, REST routes and render live in Pro).
        // Only expose its module in the builder when Pro is active — otherwise it would
        // appear but fail to fetch/render/save against the missing Pro routes.
        if (Helper::hasPro()) {
            require_once __DIR__ . '/FluentPlayerPlaylistModule.php';
            $dependencyTree->add_dependency(new FluentPlayerPlaylistModule());
        }
    }
}
