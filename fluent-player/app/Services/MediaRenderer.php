<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;
use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\UnlockService;
use FluentPlayer\App\Services\Translations\TransStrings;
use FluentPlayer\App\Views\Layers\LayerRenderer;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;
use FluentPlayer\Framework\Support\Arr;

class MediaRenderer
{
    private static $preconnectAdded = false;
    private static $instanceCounter = 0;
    private static $globalsLocalized = false;

    /**
     * Render a media post through the block pipeline.
     * Processes the post's block content via do_blocks(), so MediaBlock::render()
     * fires and handles timed content InnerBlocks.
     * Falls back to render() if the post has no block content.
     *
     * Used by the shortcode handler and dedicated player page.
     *
     * @param int $mediaId The media post ID.
     * @return string Full rendered output including player + timed content.
     */
    public static function renderFromPost($mediaId)
    {
        $mediaId = absint($mediaId);
        if (!$mediaId) {
            return '';
        }

        $post = get_post($mediaId);
        if (!$post) {
            return '';
        }

        // Content without the player block (foreign/legacy), or whose block
        // points at another media (stale/hand-edited), must not swallow or
        // swap the requested player.
        if (self::contentRendersMedia($post, $mediaId)) {
            return do_blocks($post->post_content);
        }

        $media = Media::find($mediaId);
        return Media::getStatusNotice($media) . self::render($mediaId);
    }

    private static function contentRendersMedia($post, $mediaId)
    {
        if (!function_exists('has_block')
            || !function_exists('parse_blocks')
            || !has_block('fluent-player/media', $post)
        ) {
            return false;
        }

        $stack = parse_blocks($post->post_content);
        while ($stack) {
            $block = array_pop($stack);
            if (isset($block['blockName']) && $block['blockName'] === 'fluent-player/media'
                && absint(isset($block['attrs']['mediaId']) ? $block['attrs']['mediaId'] : 0) === $mediaId
            ) {
                return true;
            }
            if (!empty($block['innerBlocks'])) {
                foreach ($block['innerBlocks'] as $inner) {
                    $stack[] = $inner;
                }
            }
        }

        return false;
    }

    /**
     * Render a media player by ID.
     * Returns only the player (no timed content). Called by MediaBlock::render()
     * for the actual player output.
     *
     * @param int    $mediaId      The media post ID.
     * @param string $extraClasses Extra CSS classes for the wrapper div.
     * @param array  $overrides    Optional in-memory settings overrides for this
     *                             render (`src`, `provider`, `posterSrc`).
     *                             Stored post meta is not mutated.
     * @return string Rendered player HTML wrapped in .fp-media-block, or empty on failure.
     */
    public static function render($mediaId, $extraClasses = '', $overrides = [])
    {
        $mediaId = absint($mediaId);
        if (!$mediaId) {
            return '';
        }

        $media = Media::find($mediaId);
        if (!$media) {
            return '';
        }

        // Locked: show the unlock form unless a valid per-media unlock cookie is present.
        if (post_password_required($mediaId) && !UnlockService::cookieUnlocked($mediaId)) {
            return self::renderLockedForm($mediaId);
        }

        if (!empty($overrides) && is_array($overrides)) {
            $currentSettings = (array) $media->settings;
            // Provider switch invalidates Mux/Bunny subfields from the saved
            // media — strip so they don't leak into localized JS.
            if (
                !empty($overrides['provider'])
                && isset($currentSettings['provider'])
                && $overrides['provider'] !== $currentSettings['provider']
            ) {
                unset($currentSettings['mux'], $currentSettings['bunny'], $currentSettings['bunny_storage'], $currentSettings['r2'], $currentSettings['stream'], $currentSettings['gumlet']);
            }
            $media->settings = array_merge($currentSettings, $overrides);
        }

        return self::renderPreparedMedia($media, $extraClasses);
    }

    /**
     * Render an ad-hoc player from resolved source settings, with no saved media
     * (id-less shortcode). $settings is the parsed inline attrs (may carry a
     * preset_slug); the named/default preset and global defaults are layered
     * downstream by prepareMediaForFrontend(). $overrides applies the resolved
     * {src, provider, posterSrc} on top. A synthetic media (ID 0) flows through
     * the same prepare/localize/view pipeline as render(); there is no post, so
     * no password gate, and the localized analytics nonce is keyed to media 0 (no
     * media record to attribute a visit to).
     *
     * @param array  $settings     Parsed inline settings (must resolve to a src).
     * @param array  $overrides    Resolved source overrides applied on top.
     * @param string $extraClasses
     * @return string
     */
    public static function renderFromSource($settings, $overrides = [], $extraClasses = '')
    {
        $settings = is_array($settings) ? $settings : [];
        if (!empty($overrides) && is_array($overrides)) {
            $settings = array_merge($settings, $overrides);
        }

        if (empty($settings['src'])) {
            return '';
        }

        $media = (object) [
            'ID'          => 0,
            'post_title'  => (string) (isset($settings['title']) ? $settings['title'] : ''),
            'post_status' => 'publish',
            'settings'    => $settings,
        ];

        return self::renderPreparedMedia($media, $extraClasses);
    }

    /**
     * Shared render tail for a prepared media object (real or synthetic): runs the
     * frontend prep, enqueues assets, localizes the per-instance config, and
     * renders the player view into the .fp-media-block wrapper.
     *
     * @param object $media        Object exposing ->ID and ->settings.
     * @param string $extraClasses
     * @return string
     */
    private static function renderPreparedMedia($media, $extraClasses = '')
    {
        do_action('fluent_player/before_render_media', $media);

        $mediaData = MediaService::prepareMediaForFrontend($media, 'shortcode');

        self::enqueueBaseAssets();

        self::addResourceHints($mediaData['media']->settings);

        Helper::enqueuePlayerStyles($mediaData['media']->ID, $mediaData['media']->settings, $mediaData['default_settings']);

        self::$instanceCounter++;
        $instance_id = $mediaData['media']->ID . '_' . self::$instanceCounter;
        $media_var_name = 'fluent_player_' . $instance_id;

        // Allow pro plugin to inject signed URLs, DRM tokens, analytics keys
        // Must run BEFORE wp_localize_script to prevent unsigned URLs leaking to page JS
        $playerSettings = apply_filters('fluent_player/player_settings', $mediaData['media']->settings);
        $mediaData['media']->settings = $playerSettings;
        $mediaData['analytics_nonce'] = wp_create_nonce('fluent_player_track_event:' . $mediaData['media']->ID);

        $localizedMediaData = $mediaData;
        $localizedMediaData['media'] = self::serializeMediaForScript($mediaData['media']);

        // The exact config this instance is handed. wp_localize_script() only
        // reaches the browser when WordPress prints the handle, which never
        // happens for a player rendered into an AJAX response — integrations
        // that inject players after the page render capture it here and deliver
        // it themselves (InitSingleFluentPlayer takes it as detail.playerData).
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $localizedMediaData = apply_filters('fluent_player/player_config', $localizedMediaData, $media_var_name, $mediaData['media']);

        wp_localize_script('fluent_player', $media_var_name, $localizedMediaData);

        self::localizeGlobals($mediaData['serverLang']);

        // The gated render is the only trusted place to hand out the fetch token.
        $accessKey = '';
        $mediaPostId = absint($mediaData['media']->ID);
        if ($mediaPostId && isset($mediaData['media']->post_status) && $mediaData['media']->post_status === 'private') {
            $accessKey = UnlockService::issueAccessToken($mediaPostId);
        }

        $app = App::make();
        $playerHtml = $app->make('view')->make('player', [
            'media_id'         => $mediaData['media']->ID,
            'instance_id'      => $instance_id,
            'media_var_name'   => $media_var_name,
            'settings'         => $playerSettings,
            'default_settings' => $mediaData['default_settings'],
            'access_key'       => $accessKey,
        ]);

        $classes = 'fp-media-block';
        if ($extraClasses) {
            $classes .= ' ' . $extraClasses;
        }

        return '<div class="' . esc_attr($classes) . '" data-media-id="' . esc_attr($mediaData['media']->ID) . '">'
             . $playerHtml
             . '</div>';
    }

    /**
     * Render a media for a page-builder placement with block/shortcode parity. With no
     * overrides it goes through renderFromPost() (do_blocks), so timed content and other
     * InnerBlocks render alongside the player; overrides force the player-only render()
     * path since do_blocks can't apply in-memory overrides. The marker class wraps the
     * do_blocks output (where render()'s $extraClasses doesn't reach).
     *
     * @param int|string $mediaId
     * @param string     $extraClasses
     * @param array      $overrides
     * @return string
     */
    public static function renderForPageBuilder($mediaId, $extraClasses = '', $overrides = [])
    {
        if (!empty($overrides) && is_array($overrides)) {
            return self::render($mediaId, $extraClasses, $overrides);
        }

        $inner = self::renderFromPost($mediaId);
        if ($inner === '' || $extraClasses === '') {
            return $inner;
        }

        return '<div class="' . esc_attr($extraClasses) . '">' . $inner . '</div>';
    }

    /**
     * Like render(), but inlines the player's localized config as a <script>. render()
     * supplies that config via wp_localize_script(), only emitted when WordPress prints
     * the handle — REST preview fetches (e.g. the Divi Visual Builder) never print
     * scripts, so inlining lets the runtime hydrate via its normal window[varName] path.
     */
    public static function renderSelfContained($mediaId, $extraClasses = '', $overrides = [])
    {
        $mediaId = absint($mediaId);
        if (!$mediaId) {
            return '';
        }

        $scripts = wp_scripts();
        // Capture only the config this render adds, so we never inline an
        // unrelated player's localized data left on the handle.
        $before = $scripts->get_data('fluent_player', 'data');

        $html = self::renderForPageBuilder($mediaId, $extraClasses, $overrides);
        if ($html === '') {
            return '';
        }

        $data = $scripts->get_data('fluent_player', 'data');
        if (is_string($before) && $before !== '' && is_string($data) && strpos($data, $before) === 0) {
            $data = substr($data, strlen($before));
        }

        if (!is_string($data) || trim($data) === '') {
            return $html;
        }

        return '<script id="fluent-player-inline-config">' . trim($data) . '</script>' . $html;
    }

    /**
     * Offset the per-request instance counter. A builder canvas renders each element in
     * its own request, so the counter restarts and repeated placements of one media all
     * serialize as `<id>_1` — duplicate DOM ids, which makes every getElementById lookup
     * (loader, controls) resolve to the first placement.
     */
    public static function seedInstanceCounter($seed)
    {
        self::$instanceCounter = max(0, (int) $seed);
    }

    /**
     * Load the player runtime + global config on a page that renders no player in its
     * initial HTML — a builder canvas injects its elements over AJAX, so whatever that
     * request enqueued is discarded and the canvas must carry the runtime up front.
     */
    public static function enqueuePlayerRuntime()
    {
        self::enqueueBaseAssets();
        self::localizeGlobals(MediaService::detectUserLanguage());
    }

    private static function enqueueBaseAssets()
    {
        Enqueue::script('fluent_player', 'js/fluent-player.js', [], FLUENT_PLAYER_VERSION, true);
        Helper::enqueueTimedContentScript();
        Enqueue::style('fluent_player_css', 'scss/public/fluent-player.scss', [], FLUENT_PLAYER_VERSION);
    }

    /**
     * Load the player runtime without rendering a player.
     * For integrations that inject a player after the page render — an AJAX
     * lightbox, a feed that swaps a thumbnail for a player on click. Nothing on
     * such a page has loaded the runtime yet, and once the markup arrives over
     * AJAX it is too late to enqueue anything, so call this during the page render.
     *
     * Passing the media that will be injected also runs its layers, so a shortcode
     * inside one registers its own assets while WordPress can still print them.
     * The layer markup is discarded — only the enqueue side effects matter.
     *
     * @param int $mediaId Optional media whose layers should register their assets.
     * @return void
     */
    public static function primeAssets($mediaId = 0)
    {
        self::enqueueBaseAssets();
        self::localizeGlobals(MediaService::detectUserLanguage());

        $mediaId = absint($mediaId);
        if (!$mediaId) {
            return;
        }

        // Layers can run form/shortcode callbacks, so resolve the media through
        // the same visibility model the embed paths use (status access +
        // can_view_media; private stays allowed) instead of a raw find().
        $media = Media::findVisible($mediaId);
        if (!$media) {
            return;
        }

        LayerRenderer::renderLayersData(Media::mergePresetSettings((array) $media->settings), $mediaId);
    }

    private static function localizeGlobals($serverLang)
    {
        if (self::$globalsLocalized) {
            return;
        }
        self::$globalsLocalized = true;

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $globalPlayerSettings = apply_filters('fluent_player/global_vars', self::buildGlobalPlayerSettings($serverLang));

        wp_localize_script('fluent_player', 'fluent_player', $globalPlayerSettings);
    }

    /**
     * The canonical `fluent_player` frontend global. Every render context must
     * localize this same payload — a subset silently disables the features whose
     * keys it omits. Callers apply the `fluent_player/global_vars` filter.
     *
     * @param string $serverLang
     * @return array
     */
    public static function buildGlobalPlayerSettings($serverLang)
    {
        $settings = Helper::getSettings();

        $globalPlayerSettings = [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('fluent_player_frontend'),
            'serverLang'      => $serverLang,
            'has_pro'         => Helper::hasPro(),
            'show_powered_by' => Helper::showPoweredBy(),
            'trans'           => TransStrings::getFrontendStrings(),
            'resume_playback' => defined('FLUENT_PLAYER_PRO_VERSION') && (bool) Arr::get($settings, 'general.resume_playback', false) === true,
            'audio_extensions' => Helper::audioExtensions(),
        ];

        if (Helper::hasPro()) {
            $globalPlayerSettings['analytics'] = Arr::get($settings, 'analytics', []);
            $globalPlayerSettings['google_analytics'] = Arr::get($settings, 'google_analytics', []);
        }

        $youtubeSettings = Arr::get($settings, 'youtube', []);
        $globalPlayerSettings['youtube'] = [
            'privacy_mode'          => (bool) Arr::get($youtubeSettings, 'privacy_mode', false),
            'show_subscribe_button' => (bool) Arr::get($youtubeSettings, 'show_subscribe_button', false),
        ];

        // Vimeo privacy (dnt). Default true preserves Vidstack's cookies=false posture.
        $vimeoSettings = Arr::get($settings, 'vimeo', []);
        $globalPlayerSettings['vimeo'] = [
            'privacy_mode'          => (bool) Arr::get($vimeoSettings, 'privacy_mode', true),
        ];

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $globalPlayerSettings['locked_message'] = apply_filters('fluent_player/media_locked_message', __('This media is password protected.', 'fluent-player'), 0);

        // Media whose watch analytics are recorded by another flow (e.g. an LMS
        // progression tracker) so the frontend analytics beacon skips them and
        // the same watch is never counted as two visits. LMS-agnostic seam.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $externalTracked = apply_filters('fluent_player/external_tracked_media', []);
        $globalPlayerSettings['external_tracked_media'] = array_values(array_unique(array_map('absint', (array) $externalTracked)));

        return $globalPlayerSettings;
    }

    /**
     * Enqueue the unlock runtime (player JS + global nonce/ajax_url, no src) and
     * return the locked form. Reusable by media and the whole-playlist gate.
     */
    public static function renderLockedForm($postId, $message = '')
    {
        self::enqueueBaseAssets();
        self::localizeGlobals(MediaService::detectUserLanguage());
        return self::lockedMarkup($postId, $message);
    }

    private static function lockedMarkup($mediaId, $message = '')
    {
        if ($message === '') {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
            $message = apply_filters('fluent_player/media_locked_message', __('This media is password protected.', 'fluent-player'), $mediaId);
        }

        $form = '<form class="fp-unlock-form">'
            . '<p class="fp-unlock-message">' . esc_html($message) . '</p>'
            . '<p class="fp-unlock-row">'
            . '<input type="password" name="password" autocomplete="off" required placeholder="' . esc_attr__('Password', 'fluent-player') . '" /> '
            . '<button type="submit">' . esc_html__('Unlock', 'fluent-player') . '</button>'
            . '</p>'
            . '<p class="fp-unlock-error" role="alert" hidden></p>'
            . '</form>';

        $html = '<div class="fp-media-block fp-media-locked fp-media-password-required" data-media-id="' . esc_attr($mediaId) . '">'
            . '<div class="fp-unlock-curtain">' . $form . '</div>'
            . '</div>';

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return apply_filters('fluent_player/media_locked_html', $html, $mediaId);
    }


    private static function addResourceHints($settings)
    {
        if (self::$preconnectAdded) {
            return;
        }

        self::$preconnectAdded = true;

        $domains = DynamicMediaSourceResolver::preconnectDomains(
            Arr::get($settings, 'provider', ''),
            Arr::get($settings, 'src', ''),
            $settings
        );

        if (empty($domains)) {
            return;
        }

        add_action('wp_head', function () use ($domains) {
            foreach ($domains as $domain) {
                $host = wp_parse_url($domain, PHP_URL_HOST);
                if ($host === false || $host === null || $host === '') {
                    continue;
                }
                echo '<link rel="dns-prefetch" href="//' . esc_attr($host) . '">' . "\n";
                echo '<link rel="preconnect" href="' . esc_url($domain) . '" crossorigin>' . "\n";
            }
        }, 1);
    }

    private static function serializeMediaForScript($media)
    {
        if (!is_object($media)) {
            return (array) $media;
        }

        $id = isset($media->ID) ? $media->ID : (isset($media->id) ? $media->id : null);
        $title = isset($media->post_title) ? $media->post_title : (isset($media->title) ? $media->title : '');

        return [
            'ID'          => $id,
            'id'          => isset($media->id) ? $media->id : $id,
            'post_title'  => $title,
            'title'       => isset($media->title) ? $media->title : $title,
            'post_status' => isset($media->post_status) ? $media->post_status : '',
            // Redacted here rather than on $media->settings so the server-side
            // render below (view + LayerRenderer) keeps the unredacted array.
            // This is NOT the only media->browser funnel: the FluentCommunity
            // nopriv AJAX route and Pro's playlist handler each serialize media
            // settings independently and must redact for themselves.
            'settings'    => Helper::redactMediaSettingsForFrontend(
                isset($media->settings) ? $media->settings : []
            ),
        ];
    }
}
