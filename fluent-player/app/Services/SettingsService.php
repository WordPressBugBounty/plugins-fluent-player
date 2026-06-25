<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;
use FluentPlayer\App\Helpers\Helper;

class SettingsService
{
    /**
     * Option key for storing settings
     */
    const SETTINGS_KEY = 'fluent_player_settings';

    protected static $settingsCache = null;

    /**
     * Default settings
     * @var array
     */
    protected static $defaults = [
        'general'          => [
            'default_aspect_ratio' => 'original',
            'default_preset'       => 'course',
            'resume_playback'      => false,
            'custom_css'           => ''
        ],
        'youtube'          => [
            'privacy_mode'          => false,
            'show_subscribe_button' => false,
        ],
        'performance'      => [
            'dynamic_load_js' => false
        ],
        'analytics'        => [
            'enabled'        => false,
            'auto_cleanup'   => [
                'enabled' => true,
                'days'    => 30,
            ],
        ],
        'google_analytics' => [
            'enabled'          => false,
            'use_existing_tag' => true,
            'measurement_id'   => '',
        ],
        'branding'         => [
            'brand_color'       => '#DD1F13',
            'control_bar_color' => '#171717A6',
            'logo_url'          => '',
            'logo_link'         => '',
            'logo_position'     => 'top-right',
            'logo_width'        => 24,
        ],
        'subtitle_service' => [
            'enabled'         => false,
            'service_url'     => '',
            'api_token'       => '',
            'timeout_seconds' => 45,
        ],
    ];

    /**
     * Get all settings
     * @return array
     */
    public static function getSettings()
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $savedSettings = get_option(self::SETTINGS_KEY, []);

        if (!$savedSettings || !is_array($savedSettings)) {
            $savedSettings = [];
        }

        $defaults = self::$defaults;

        $savedSettings = array_replace_recursive($defaults, $savedSettings);

        // Legacy: older installs stored default preset only under presets.default_preset
        $generalPreset = Arr::get($savedSettings, 'general.default_preset');
        if ($generalPreset === null || $generalPreset === '') {
            $legacy = Arr::get($savedSettings, 'presets.default_preset');
            if ($legacy !== null && $legacy !== '') {
                $savedSettings['general']['default_preset'] = $legacy;
            }
        }

        // Custom JS has been removed — never surface a legacy stored value.
        if (isset($savedSettings['general']['custom_js'])) {
            unset($savedSettings['general']['custom_js']);
        }

        // Removed dead settings — never surface legacy stored values (these were
        // seeded into existing installs via the save-merge, so strip on read).
        // The legacy presets.default_preset migration above runs first, so the
        // global presets section can be dropped here:
        //  - general.brand_color → use branding.brand_color
        //  - email_capture (global) → superseded by per-preset email_capture
        //  - presets (global) → superseded by the fluent_player_presets option
        if (isset($savedSettings['general']) && is_array($savedSettings['general'])) {
            unset($savedSettings['general']['brand_color']);
        }
        unset($savedSettings['email_capture'], $savedSettings['presets']);

        self::$settingsCache = $savedSettings;

        return $savedSettings;
    }

    public static function clearCache()
    {
        self::$settingsCache = null;
    }

    /**
     * Get a specific section of settings
     *
     * @param string $section
     *
     * @return array
     */
    public static function getSection($section)
    {
        $settings = self::getSettings();
        $sectionSettings = Arr::get($settings, $section, Arr::get(self::$defaults, $section, []));

        return apply_filters("fluent_player/settings_section/{$section}", $sectionSettings, $settings);
    }

    /**
     * Get a specific setting value
     *
     * @param string $key Dot notation key (e.g., 'performance.dynamic_load_js')
     * @param mixed $default Default value if setting doesn't exist
     *
     * @return mixed
     */
    public static function getSetting($key, $default = null)
    {
        $settings = self::getSettings();
        return Arr::get($settings, $key, $default);
    }

    /**
     * Top-level setting keys that are allowed to be saved (whitelist).
     */
    protected static $allowedSettingKeys = [
        'general', 'youtube', 'performance', 'analytics', 'google_analytics',
        'branding', 'subtitle_service',
    ];

    public static function saveSettings($settings)
    {
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings = array_intersect_key($settings, array_flip(self::$allowedSettingKeys));
        $settings = self::normalizeNonPersistentSettings($settings);

        $sanitizedSettings = self::sanitizeSettings($settings);

        $existingSettings = self::getSettings();
        $mergedSettings = array_replace_recursive($existingSettings, $sanitizedSettings);

        // Save settings
        self::save($mergedSettings);

        return $mergedSettings;
    }

    /**
     * Update a specific section of settings
     *
     * @param string $section
     * @param array $sectionSettings
     *
     * @return array Updated settings
     */
    public static function updateSection($section, $sectionSettings)
    {
        $settings = self::getSettings();
        $settings[$section] = $sectionSettings;

        return self::saveSettings($settings);
    }

    /**
     * Update a specific setting value
     *
     * @param string $key Dot notation key (e.g., 'performance.dynamic_load_js')
     * @param mixed $value
     *
     * @return array Updated settings
     */
    public static function updateSetting($key, $value)
    {
        $settings = self::getSettings();
        Arr::set($settings, $key, $value);

        return self::saveSettings($settings);
    }

    /**
     * Save settings to database
     *
     * @param array $settings
     *
     * @return bool
     */
    protected static function save($settings)
    {
        $result = update_option(self::SETTINGS_KEY, $settings);
        self::clearCache();
        return $result;
    }

    protected static function normalizeNonPersistentSettings($settings)
    {
        if (!is_array($settings)) {
            return [];
        }

        if (isset($settings['subtitle_service']) && is_array($settings['subtitle_service'])) {
            unset($settings['subtitle_service']['service_url'], $settings['subtitle_service']['api_token']);
        }

        // Custom JS and the dead general.brand_color duplicate have been removed —
        // never persist them. (Global email_capture/presets are dropped earlier by
        // the $allowedSettingKeys allowlist in saveSettings.)
        if (isset($settings['general']) && is_array($settings['general'])) {
            unset($settings['general']['custom_js'], $settings['general']['brand_color']);
        }

        return $settings;
    }

    /**
     * Sanitize settings before saving
     *
     * @param array $settings
     *
     * @return array
     */
    protected static function sanitizeSettings($settings)
    {
        // Define sanitization rules settings
        $sanitizationRules = [
            // General settings
            'general.custom_css'                => 'wpfp_sanitize_css',
            'general.default_aspect_ratio'      => 'sanitize_text_field',
            'general.default_preset'            => 'sanitize_text_field',
            'general.resume_playback'           => 'rest_sanitize_boolean',

            // YouTube settings
            'youtube.privacy_mode'              => 'rest_sanitize_boolean',
            'youtube.show_subscribe_button'     => 'rest_sanitize_boolean',

            // Performance settings
            'performance.dynamic_load_js'       => 'rest_sanitize_boolean',

            // Analytics settings
            'analytics.enabled'                 => 'rest_sanitize_boolean',
            'analytics.google_analytics_id'     => 'sanitize_text_field',
            'analytics.auto_cleanup.enabled'    => 'rest_sanitize_boolean',
            'analytics.auto_cleanup.days'       => 'absint',

            // Google Analytics settings
            'google_analytics.enabled'          => 'rest_sanitize_boolean',
            'google_analytics.use_existing_tag' => 'rest_sanitize_boolean',
            'google_analytics.measurement_id'   => 'sanitize_text_field',

            // Branding settings
            'branding.brand_color'              => 'sanitize_text_field',
            'branding.control_bar_color'        => 'sanitize_text_field',
            'branding.logo_url'                 => 'escUrlRaw',
            'branding.logo_link'                => 'escUrlRaw',
            'branding.logo_position'            => 'sanitize_text_field',
            'branding.logo_width'               => 'intval',

            // Subtitle service settings
            'subtitle_service.enabled'          => 'rest_sanitize_boolean',
            'subtitle_service.service_url'      => 'escUrlRaw',
            'subtitle_service.api_token'        => 'sanitize_text_field',
            'subtitle_service.timeout_seconds'  => 'absint',
        ];

        return Sanitizer::sanitize($settings, $sanitizationRules);
    }


    /**
     * Reset settings to defaults
     * @return array
     */
    public static function resetSettings()
    {
        self::save(self::$defaults);
        return self::$defaults;
    }

    /**
     * Delete all settings
     * @return bool
     */
    public static function deleteSettings()
    {
        return delete_option(self::SETTINGS_KEY);
    }

    /**
     * Static helper to get a setting value from anywhere
     *
     * @param string $key Dot notation key (e.g., 'performance.dynamic_load_js')
     * @param mixed $default Default value if setting doesn't exist
     *
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        return self::getSetting($key, $default);
    }

    /**
     * Resolved default preset slug (general.default_preset, legacy presets.default_preset, or course).
     *
     * @return string
     */
    public static function getDefaultPresetSlug()
    {
        return self::resolveDefaultPresetSlug(self::getSettings());
    }

    /**
     * @param array $globalSettings Merged settings array from getSettings()
     *
     * @return string
     */
    protected static function resolveDefaultPresetSlug($globalSettings)
    {
        $slug = Arr::get($globalSettings, 'general.default_preset');
        if ($slug !== null && $slug !== '') {
            return (string) $slug;
        }
        $legacy = Arr::get($globalSettings, 'presets.default_preset');
        if ($legacy !== null && $legacy !== '') {
            return (string) $legacy;
        }

        return 'course';
    }

    /**
     * Get all front-end player settings
     *
     * @param array $mediaSettings Optional media-specific settings to merge
     *
     * @return array Organized settings for front-end rendering
     */
    public static function getMediaDefaultSettings($mediaSettings = [])
    {
        $globalSettings = self::getSettings();
        $useCustomBranding = Arr::get($mediaSettings, 'useCustomBranding', false);

        // Get branding settings (custom or global)
        if ($useCustomBranding) {
            $brandColor = Arr::get($mediaSettings, 'brandingColor') ?: Helper::DEFAULT_BRAND_COLOR;
            $logoUrl = Arr::get($mediaSettings, 'logo', '');
            $logoLink = Arr::get($mediaSettings, 'logoLink', '');
            $logoPosition = Arr::get($mediaSettings, 'logoPosition', 'position-top-right');
            $logoWidth = Arr::get($mediaSettings, 'logoWidth', 24);
        } else {
            $brandColor = Arr::get($globalSettings, 'branding.brand_color', Helper::DEFAULT_BRAND_COLOR);
            $logoUrl = Arr::get($globalSettings, 'branding.logo_url', '');
            $logoLink = Arr::get($globalSettings, 'branding.logo_link', '');
            $logoPosition = Arr::get($globalSettings, 'branding.logo_position', 'top-right');
            $logoWidth = Arr::get($globalSettings, 'branding.logo_width', 24);

            // Add position prefix if needed
            $logoPosition = (string)$logoPosition;
            if (strpos($logoPosition, 'position-') !== 0) {
                $logoPosition = 'position-' . $logoPosition;
            }
        }

        // Get control bar color (custom or global)
        if ($useCustomBranding) {
            $controlBarColor = Arr::get($mediaSettings, 'controlBarColor', '');
        } else {
            $controlBarColor = Arr::get($globalSettings, 'branding.control_bar_color', '');
        }

        // Create default settings array
        $defaultSettings = [
            'brandColor'         => $brandColor,
            'controlBarColor'    => $controlBarColor,
            'preload'            => self::getDefaultPreload($mediaSettings, $globalSettings),
            'customCSS'          => Arr::get($globalSettings, 'general.custom_css', ''),
            'logoUrl'            => $logoUrl,
            'logoLink'           => $logoLink,
            'logoPosition'       => $logoPosition,
            'logoWidth'          => $logoWidth,
            'aspectRatio'        => self::getAspectRatio($mediaSettings, $globalSettings),
            'defaultAspectRatio' => Arr::get($globalSettings, 'general.default_aspect_ratio', 'original'),
            'youtube'            => [
                'privacyMode'         => Arr::get($globalSettings, 'youtube.privacy_mode', false),
                'showSubscribeButton' => Arr::get($globalSettings, 'youtube.show_subscribe_button', false),
            ],
            'defaultPresetSlug'  => self::resolveDefaultPresetSlug($globalSettings),
            'dynamicLoadJs'      => Arr::get($globalSettings, 'performance.dynamic_load_js', false),
            'useBrowserStorage'  => Helper::hasPro() ? Arr::get($globalSettings, 'general.resume_playback', false) : false,
            'google_analytics'   => [
                'enabled'          => Arr::get($globalSettings, 'google_analytics.enabled', false),
                'use_existing_tag' => Arr::get($globalSettings, 'google_analytics.use_existing_tag', false),
                'measurement_id'   => Arr::get($globalSettings, 'google_analytics.measurement_id', ''),
            ],
        ];

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return apply_filters('fluent_player/media_default_settings', $defaultSettings, $mediaSettings, $globalSettings);
    }

    /**
     * Resolve the default media preload hint.
     *
     * @param array $mediaSettings
     * @param array $globalSettings
     * @return string
     */
    public static function getDefaultPreload($mediaSettings = [], $globalSettings = [])
    {
        $defaultPreload = 'metadata';

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $preload = apply_filters('fluent_player/default_preload', $defaultPreload, $mediaSettings, $globalSettings);

        return in_array($preload, ['none', 'metadata', 'auto'], true) ? $preload : $defaultPreload;
    }

    /**
     * Get playlist-specific settings and generate CSS
     *
     * @param array $playlist The playlist data
     * @param array $defaultSettings Default media settings
     * @param string $playlistId The playlist ID for CSS targeting
     *
     * @return array Contains CSS styles and processed settings
     */
    public static function getPlaylistDefaultSettings($originalPlaylist, $playlistId)
    {
        $globalSettings = self::getSettings();

        $appearanceSettings = Arr::get($originalPlaylist, 'appearance', []);

        // Process brand color
        $brandColor = Arr::get($appearanceSettings, 'brandColor', []);

        $globalBrand = Arr::get($globalSettings, 'branding.brand_color', Helper::DEFAULT_BRAND_COLOR);
        $brandColorValue = $globalBrand;

        if (is_array($brandColor) && $mode = Arr::get($brandColor, 'mode')) {
            if ($mode === 'custom' && $value = Arr::get($brandColor, 'value')) {
                $brandColorValue = $value;
            } elseif ($mode === 'auto') {
                $brandColorValue = self::resolvePlaylistAutoBrandColor($originalPlaylist, $globalBrand);
            }
        }

        // Process border radius
        $borderRadius = Arr::get($appearanceSettings, 'borderRadius.value', 8) . Arr::get($appearanceSettings, 'borderRadius.unit', 'px');

        // Process typography
        $typographySettings = Arr::get($originalPlaylist, 'typography', []);
        $formattedTypography = [];

        if (is_array($typographySettings) && Arr::isTrue($typographySettings, 'enabled')) {
            $fontSize = Arr::get($typographySettings, 'fontSize.value');
            if ($fontSize) {
                $fontSize = $fontSize . Arr::get($typographySettings, 'fontSize.unit', 'px');
                $formattedTypography['fontSize'] = $fontSize;
            }
            if ($fontWeight = Arr::get($typographySettings, 'fontWeight')) {
                $formattedTypography['fontWeight'] = $fontWeight;
            }
            if ($lineHeight = Arr::get($typographySettings, 'lineHeight')) {
                $formattedTypography['lineHeight'] = $lineHeight;
            }
        }

        // Process spacing
        $margin = $padding = '';
        if (Arr::isTrue($originalPlaylist, 'layout.spacing')) {
            $margin = self::getSpacingValue(Arr::get($originalPlaylist, 'layout.margin'));
            $padding = self::getSpacingValue(Arr::get($originalPlaylist, 'layout.padding'));
        }

        // Process Box Shadow
        $boxShadow = '';
        if (Arr::isTrue($appearanceSettings, 'boxShadow.enabled')) {
            $boxShadow = self::getBoxShadowValue(Arr::get($appearanceSettings, 'boxShadow'));
        }

        $playlistSettings = [
            'brandColor'        => $brandColorValue,
            'borderRadius'      => $borderRadius,
            'backgroundColor'   => Arr::get($appearanceSettings, 'backgroundColor', ''),
            'textColor'         => Arr::get($appearanceSettings, 'textColor', ''),
            'aspectRatio'       => self::getPlaylistAspectRatio($originalPlaylist, $globalSettings),
            'typography'        => $formattedTypography,
            'margin'            => $margin,
            'padding'           => $padding,
            'boxShadow'         => $boxShadow,
            'behavior'          => Arr::get($originalPlaylist, 'behavior', []),
            'layoutType'        => Arr::get($originalPlaylist, 'layout.type', 'standard'),
            'gridColumns'       => (int)Arr::get($originalPlaylist, 'grid.columns', 3),
            'useBrowserStorage' => Helper::hasPro() ? Arr::get($globalSettings, 'general.resume_playback', false) : false,
            'google_analytics'  => [
                'enabled'          => Arr::get($globalSettings, 'google_analytics.enabled', false),
                'use_existing_tag' => Arr::get($globalSettings, 'google_analytics.use_existing_tag', false),
                'measurement_id'   => Arr::get($globalSettings, 'google_analytics.measurement_id', ''),
            ],
        ];
        return apply_filters('fluent_player/frontend_playlist_settings', $playlistSettings, $originalPlaylist, $playlistId);
    }

    /**
     * Mirror BasePlaylistLayout::resolveBrandColor for the `auto` mode.
     *
     * Looks up the playlist's preset_source_media_id (falling back to the first
     * media), fetches that media's settings via Media::getMediaSettings(), and
     * runs them through getMediaDefaultSettings() — the same path standalone
     * playback uses. Falls back to the playlist-level global brand when no
     * source media is available.
     */
    private static function resolvePlaylistAutoBrandColor($playlistSettings, $globalBrand)
    {
        if (!is_array($playlistSettings)) {
            return $globalBrand;
        }

        $mediaIds = Arr::get($playlistSettings, 'medias', []);
        if (!is_array($mediaIds) || empty($mediaIds)) {
            return $globalBrand;
        }

        $configuredId = absint(Arr::get($playlistSettings, 'preset_source_media_id', 0));
        $sourceId = 0;

        if ($configuredId) {
            foreach ($mediaIds as $candidateId) {
                if (absint($candidateId) === $configuredId) {
                    $sourceId = $configuredId;
                    break;
                }
            }
        }

        if (!$sourceId) {
            $sourceId = absint(reset($mediaIds));
        }

        if (!$sourceId) {
            return $globalBrand;
        }

        $sourceMedia = \FluentPlayer\App\Models\Media::find($sourceId);
        $sourceSettings = $sourceMedia ? \FluentPlayer\App\Models\Media::getMediaSettings($sourceMedia) : null;

        if (!is_array($sourceSettings) || empty($sourceSettings)) {
            return $globalBrand;
        }

        $sourceDefaults = self::getMediaDefaultSettings($sourceSettings);
        $sourceBrand = Arr::get($sourceDefaults, 'brandColor');

        return !empty($sourceBrand) ? $sourceBrand : $globalBrand;
    }

    private static function getSpacingValue($spacing)
    {
        $top = Arr::get($spacing, 'top', 0);
        $right = Arr::get($spacing, 'right', 0);
        $bottom = Arr::get($spacing, 'bottom', 0);
        $left = Arr::get($spacing, 'left', 0);
        $unit = Arr::get($spacing, 'unit', 'px');
        if (Arr::isTrue($spacing, 'linked')) {
            return $top . $unit;
        }
        return $top . $unit . ' ' . $right . $unit . ' ' . $bottom . $unit . ' ' . $left . $unit;
    }

    private static function getBoxShadowValue($boxShadow)
    {
        $horizontal = Arr::get($boxShadow, 'horizontal', 0);
        $vertical = Arr::get($boxShadow, 'vertical', 0);
        $blur = Arr::get($boxShadow, 'blur', 0);
        $spread = Arr::get($boxShadow, 'spread', 0);
        $color = Arr::get($boxShadow, 'color', '');
        $color = $color !== '' ? $color : 'transparent';
        $inset = '';
        if ('inset' === Arr::get($boxShadow, 'position')) {
            $inset = ' inset';
        }
        return $inset . ' ' . $horizontal . 'px ' . $vertical . 'px ' . $blur . 'px ' . $spread . 'px ' . $color;
    }

    private static function getAspectRatio($mediaSettings, $settings)
    {
        $aspectRatio = Arr::get($mediaSettings, 'aspectRatio');

        if (empty($aspectRatio) || $aspectRatio == 'default') {
            $aspectRatio = Arr::get($settings, 'general.default_aspect_ratio', 'original');
            $aspectRatio = str_replace(':', ' / ', $aspectRatio);
        } elseif ($aspectRatio === 'original') {
            $aspectRatio = false;
        }

        return $aspectRatio;
    }

    private static function getPlaylistAspectRatio($playlistSettings, $settings)
    {
        $aspectRatio = Arr::get($playlistSettings, 'layout.thumbnailRatio');

        if (empty($aspectRatio) || $aspectRatio == 'default') {
            $aspectRatio = Arr::get($settings, 'general.default_aspect_ratio', 'original');
            $aspectRatio = str_replace(':', ' / ', $aspectRatio);
        } elseif ($aspectRatio === 'original') {
            $aspectRatio = false;
        } else {
            $aspectRatio = str_replace(':', ' / ', $aspectRatio);
        }

        return $aspectRatio;
    }
}
