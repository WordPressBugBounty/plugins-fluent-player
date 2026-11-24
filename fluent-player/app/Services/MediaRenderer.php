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
    private static $shortcodeOverrides = [];

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
        if (self::findMediaBlock($post, $mediaId)) {
            return do_blocks($post->post_content);
        }

        $media = Media::find($mediaId);
        return Media::getStatusNotice($media) . self::render($mediaId);
    }

    /**
     * Render a media post's player block with shortcode overrides, so timed content renders
     * alongside them. Only the matched block renders; it carries a one-time token that
     * MediaBlock::render() trades for the overrides, which render() merges before frontend
     * prep (iOS mute, signing, clip rule). Falls back to the player-only render() when the
     * post has no matching media block.
     *
     * @param int    $mediaId
     * @param string $extraClasses Classes for the player wrapper.
     * @param array  $overrides
     * @return string
     */
    public static function renderFromPostWithOverrides($mediaId, $extraClasses, array $overrides)
    {
        $mediaId = absint($mediaId);
        $post = $mediaId ? get_post($mediaId) : null;
        $block = $post ? self::findMediaBlock($post, $mediaId) : null;
        if (!$block) {
            return Media::getStatusNotice(Media::find($mediaId)) . self::render($mediaId, $extraClasses, $overrides);
        }

        // A random token, not the overrides, goes on the block so saved block markup can't forge them.
        $token = wp_generate_uuid4();
        self::$shortcodeOverrides[$token] = $overrides;
        $block['attrs']['shortcodeOverridesToken'] = $token;
        if ($extraClasses !== '') {
            $block['attrs']['className'] = trim(Arr::get($block, 'attrs.className', '') . ' ' . $extraClasses);
        }

        try {
            return render_block($block);
        } finally {
            unset(self::$shortcodeOverrides[$token]);
        }
    }

    /**
     * @param mixed $token The block's shortcodeOverridesToken attribute.
     * @return array The overrides registered for this token, or [] for an unknown token.
     */
    public static function shortcodeOverridesFor($token)
    {
        return is_string($token) && isset(self::$shortcodeOverrides[$token])
            ? self::$shortcodeOverrides[$token]
            : [];
    }

    public static function findMediaBlock($post, $mediaId)
    {
        if (!function_exists('has_block')
            || !function_exists('parse_blocks')
            || !has_block('fluent-player/media', $post)
        ) {
            return null;
        }

        $stack = parse_blocks($post->post_content);
        while ($stack) {
            $block = array_pop($stack);
            if (isset($block['blockName']) && $block['blockName'] === 'fluent-player/media'
                && absint(isset($block['attrs']['mediaId']) ? $block['attrs']['mediaId'] : 0) === $mediaId
            ) {
                return $block;
            }
            if (!empty($block['innerBlocks'])) {
                foreach ($block['innerBlocks'] as $inner) {
                    $stack[] = $inner;
                }
            }
        }

        return null;
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

        // An explicit `clip` override (block selection, shortcode window) wins over the default-clip rule.
        $hasExplicitClip = !empty($overrides) && is_array($overrides) && array_key_exists('clip', $overrides);
        if (!$hasExplicitClip) {
            $settings = (array) $media->settings;
            // A substituted source is a different video, so the template media's clip does not apply.
            $clip = empty($overrides['src']) ? ClipService::defaultClip($mediaId, $settings) : null;
            if ($clip !== null) {
                $settings['clip'] = $clip;
            } else {
                unset($settings['clip']);
            }
            $media->settings = $settings;
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

        // This path already embeds a signed src, so language variants must match it.
        // nopriv payload builders keep the unsigned default.
        $mediaData = MediaService::prepareMediaForFrontend($media, 'shortcode', true);

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

        if (!has_action('wp_footer', [self::class, 'printInertConfigBlocks'])) {
            add_action('wp_footer', [self::class, 'printInertConfigBlocks'], 19);
        }

        self::localizeGlobals($mediaData['serverLang']);

        // The gated render is the only trusted place to hand out the fetch token.
        $accessKey = '';
        $mediaPostId = absint($mediaData['media']->ID);
        if ($mediaPostId && isset($mediaData['media']->post_status) && $mediaData['media']->post_status === 'private') {
            $accessKey = UnlockService::issueAccessToken($mediaPostId);
        }

        // Only for media that actually carry a signed source, so an unsigned provider
        // never gets a capability minted for it.
        //
        // Private is excluded deliberately. isStatusEmbeddable() lets a private media
        // render for anyone — that is what the access key above is for — but
        // PlaybackController requires read_post before it will re-sign one. Issuing a
        // grant here would guarantee a 403 on every refresh plus a console warning
        // blaming the TTL filter, which cannot help.
        $srcExpiresAt = absint(Arr::get($playerSettings, 'signed_url_expires_at', 0));
        $playbackGrant = '';
        if ($mediaPostId && $srcExpiresAt > 0 && get_post_status($mediaPostId) !== 'private') {
            $playbackGrant = UnlockService::issuePlaybackGrant($mediaPostId);
        }

        $app = App::make();
        $playerHtml = $app->make('view')->make('player', [
            'media_id'         => $mediaData['media']->ID,
            'instance_id'      => $instance_id,
            'media_var_name'   => $media_var_name,
            'settings'         => $playerSettings,
            'default_settings' => $mediaData['default_settings'],
            'access_key'       => $accessKey,
            'fluent_player_share' => ShareService::getShareData($mediaData['media']->ID),
            'src_expires_at'   => $srcExpiresAt,
            'playback_grant'   => $playbackGrant,
        ]);

        $classes = 'fp-media-block';
        if ($extraClasses) {
            $classes .= ' ' . $extraClasses;
        }

        // The counter restarts in AJAX renders (modals), so add a random part to keep the scope unique
        $brandingScope = $instance_id . '_' . wp_rand(100000, 999999);

        // Inside the wrapper: core adds the block's custom CSS class to the first tag of the output
        $brandingStyle = Helper::brandingStyleTag($brandingScope, $playerSettings, $mediaData['default_settings']);

        return '<div class="' . esc_attr($classes) . '" data-media-id="' . esc_attr($mediaData['media']->ID) . '" data-fp-instance="' . esc_attr($brandingScope) . '">'
             . $brandingStyle
             . $playerHtml
             . '</div>';
    }

    /**
     * Render a media for a page-builder placement with block/shortcode parity. Goes through
     * renderFromPost() (do_blocks) so timed content and other InnerBlocks render alongside the
     * player. Per-element overrides are applied within that path via a scoped
     * fluent_player/player_settings filter at priority 20 (after Pro's signing). Only
     * presentational keys are accepted, so an override can never clobber a signed src/DRM field.
     *
     * @param int|string $mediaId
     * @param string     $extraClasses
     * @param array      $overrides
     * @return string
     */
    public static function renderForPageBuilder($mediaId, $extraClasses = '', $overrides = [])
    {
        // Allow-list presentational keys only — never signed src/DRM subfields — so the
        // array_merge below is safe by construction regardless of what a caller passes.
        $allowed = ['autoplay', 'loop', 'muted', 'mutedAutoplay', 'controls'];
        $rawOverrides = is_array($overrides) ? $overrides : [];
        $overrides = array_intersect_key($rawOverrides, array_flip($allowed));

        // Surface silently-dropped (non-presentational) override keys under WP_DEBUG so a caller
        // passing e.g. `poster`/`startTime` gets a signal rather than a mystery no-op.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $dropped = array_keys(array_diff_key($rawOverrides, $overrides));
            if (!empty($dropped)) {
                error_log('[fluent-player] renderForPageBuilder ignored non-presentational override keys: ' . implode(', ', $dropped)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        $filter = null;
        if (!empty($overrides)) {
            $filter = function ($settings) use ($overrides) {
                return is_array($settings) ? array_merge($settings, $overrides) : $settings;
            };
            add_filter('fluent_player/player_settings', $filter, 20);
        }

        try {
            $inner = self::renderFromPost($mediaId);
        } finally {
            if ($filter !== null) {
                remove_filter('fluent_player/player_settings', $filter, 20);
            }
        }

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
     * Inert JSON copy of one player's localized config, read by the runtime when the
     * localized script never runs (a consent tool blocks it, or a builder canvas injects
     * the markup with innerHTML).
     *
     * @param string $varName Instance var name, e.g. fluent_player_12_1.
     * @return string The application/json block, or '' when nothing is localized for it.
     */
    public static function inertConfigBlock($varName)
    {
        $json = self::localizedConfigJson($varName);

        return $json === '' ? '' : wp_get_inline_script_tag($json, self::inertConfigAttributes($varName));
    }

    /**
     * Footer copies of every player config when the shared localized script holds an
     * embed URL (main src, language variant, ...). Consent tools block that whole
     * script, which strands every player on the page, not only the embed one.
     *
     * @return void
     */
    public static function printInertConfigBlocks()
    {
        $data = wp_scripts()->get_data('fluent_player', 'data');
        if (!is_string($data) || !self::mentionsEmbedHost($data)) {
            return;
        }

        if (!preg_match_all('/^var (fluent_player_[A-Za-z0-9_]+) = /m', $data, $matches)) {
            return;
        }

        foreach (array_unique($matches[1]) as $varName) {
            $json = self::localizedConfigJson($varName);
            if ($json !== '') {
                wp_print_inline_script_tag($json, self::inertConfigAttributes($varName));
            }
        }
    }

    /**
     * Whether localized script data names an embed host consent tools block on.
     *
     * @param string $data
     * @return bool
     */
    private static function mentionsEmbedHost($data)
    {
        foreach (array_keys(Helper::embedProviderHosts()) as $domain) {
            if ($domain !== '' && stripos($data, (string) $domain) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Script tag attributes the runtime's readInlineConfig() looks up.
     *
     * @param string $varName
     * @return array<string, string>
     */
    private static function inertConfigAttributes($varName)
    {
        return [
            'type'          => 'application/json',
            'class'         => 'fp-inline-config',
            'data-var-name' => $varName,
        ];
    }

    /**
     * One player's localized config, re-encoded for a non-executing script block.
     *
     * @param string $varName
     * @return string JSON, or '' when nothing is localized for it.
     */
    private static function localizedConfigJson($varName)
    {
        $data = wp_scripts()->get_data('fluent_player', 'data');
        if (!is_string($data) || $data === '') {
            return '';
        }

        foreach (explode("\n", $data) as $line) {
            if (!preg_match('/^var ([A-Za-z0-9_]+) = (.+);$/', trim($line), $match) || $match[1] !== $varName) {
                continue;
            }

            $decoded = json_decode($match[2], true);
            if (!is_array($decoded)) {
                return '';
            }

            // JSON_HEX_TAG so a payload containing `</script>` cannot close the block
            $json = wp_json_encode($decoded, JSON_HEX_TAG | JSON_HEX_AMP);

            return is_string($json) ? $json : '';
        }

        return '';
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
            'playback_source_url' => Helper::playbackSourceUrl(),
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

        // Optional site-wide Dailymotion Player ID; empty uses the player's public fallback.
        $globalPlayerSettings['dailymotion'] = [
            'player_id' => (string) Arr::get($settings, 'dailymotion.player_id', ''),
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
