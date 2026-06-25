<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class PresetService
{
    protected static $cache = null;
    const OPTION_KEY = 'fluent_player_presets';
    const RESERVED_SLUGS = ['default', 'course', 'simple', 'minimal', 'standard', 'floating', 'ambient'];

    public static function all()
    {
        if (static::$cache !== null) {
            return static::$cache;
        }
        $raw = get_option(self::OPTION_KEY, '{}');
        $presets = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (!is_array($presets)) {
            $presets = [];
        }

        $defaults = self::getDefaultPresets();

        foreach ($presets as $slug => &$preset) {
            if (!is_array($preset)) {
                $preset = [];
            }

            $settings = Arr::get($preset, 'settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }

            $preset['slug'] = Arr::get($preset, 'slug', $slug);
            $preset['settings'] = self::normalizePresetSettings($settings, (string) $preset['slug']);

            if (empty($preset['description']) && isset($defaults[$slug]['description'])) {
                $preset['description'] = $defaults[$slug]['description'];
            }
        }
        unset($preset);

        static::$cache = $presets;
        return $presets;
    }

    public static function find($slug)
    {
        if (!$slug) {
            return null;
        }
        $presets = static::all();
        return Arr::get($presets, $slug);
    }

    public static function getDefault()
    {
        $defaultSlug = SettingsService::getDefaultPresetSlug();
        if ($defaultSlug) {
            $preset = static::find($defaultSlug);
            if ($preset) {
                return $preset;
            }
        }
        $presets = static::all();
        return !empty($presets) ? reset($presets) : null;
    }

    public static function getDefaultSlug()
    {
        $default = static::getDefault();
        return $default ? Arr::get($default, 'slug') : null;
    }

    public static function save($slug, $data)
    {
        $presets = static::all();
        $requestedSlug = sanitize_title($slug);
        $data = self::sanitizePresetData($slug, $data);
        $slug = Arr::get($data, 'slug', $requestedSlug);

        if ($slug === '') {
            $slug = sanitize_title($data['name'] ?? 'preset');
        }

        if ($requestedSlug && $requestedSlug !== $slug && isset($presets[$requestedSlug])) {
            unset($presets[$requestedSlug]);
        }

        $presets[$slug] = $data;
        update_option(self::OPTION_KEY, wp_json_encode($presets), true);
        static::$cache = $presets;
        return $data;
    }

    public static function delete($slug)
    {
        if (in_array($slug, self::RESERVED_SLUGS)) {
            return false;
        }
        $presets = static::all();
        if (!Arr::get($presets, $slug)) {
            return false;
        }
        unset($presets[$slug]);
        update_option(self::OPTION_KEY, wp_json_encode($presets), true);
        static::$cache = $presets;
        return true;
    }

    public static function clearCache()
    {
        static::$cache = null;
    }

    /**
     * Sanitize preset payloads before persisting to wp_options.
     *
     * @param string $slug
     * @param mixed  $data
     * @return array
     */
    protected static function sanitizePresetData($slug, $data)
    {
        if (!is_array($data)) {
            $data = [];
        }

        $settings = Arr::get($data, 'settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return [
            'name'        => Sanitizer::sanitizeTextField(Arr::get($data, 'name', '')),
            'slug'        => sanitize_title(Arr::get($data, 'slug', $slug)),
            'description' => Sanitizer::sanitizeTextField(Arr::get($data, 'description', '')),
            'settings'    => self::normalizePresetSettings(
                SettingsSanitizer::sanitizePresetSettings($settings),
                sanitize_title(Arr::get($data, 'slug', $slug))
            ),
        ];
    }

    public static function maybeCreateDefaults()
    {
        $presets = static::all();

        if (empty($presets)) {
            try {
                $defaults = self::getDefaultPresets();
                update_option(self::OPTION_KEY, wp_json_encode($defaults), true);
                static::$cache = $defaults;
                SettingsService::updateSetting('general.default_preset', 'course');
            } catch (\Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Error logging for debugging
                    error_log('Fluent Player: Could not create default presets - ' . $e->getMessage());
                }
            }
            return;
        }

        // Merge any new built-in presets that don't exist yet
        $defaults = self::getDefaultPresets();
        $updated = false;
        foreach ($defaults as $slug => $preset) {
            if (!isset($presets[$slug])) {
                $presets[$slug] = $preset;
                $updated = true;
            }
        }

        if ($updated) {
            update_option(self::OPTION_KEY, wp_json_encode($presets), true);
            static::$cache = $presets;
        }
    }

    /**
     * Sync shipped built-in preset defaults for reserved presets only.
     * This keeps built-in presets aligned across updates without touching custom presets.
     */
    public static function syncBuiltInControls()
    {
        $presets = static::all();
        if (empty($presets)) {
            return;
        }

        $defaults = self::getDefaultPresets();
        $updated = false;

        foreach ($defaults as $slug => $defaultPreset) {
            if (!isset($presets[$slug])) {
                continue;
            }

            $defaultControls = Arr::get($defaultPreset, 'settings.controls', []);
            $currentControls = Arr::get($presets[$slug], 'settings.controls', []);

            foreach (['settings', 'playback_speed', 'captions_toggle'] as $controlKey) {
                if (
                    array_key_exists($controlKey, $defaultControls) &&
                    Arr::get($currentControls, $controlKey) !== $defaultControls[$controlKey]
                ) {
                    $presets[$slug]['settings']['controls'][$controlKey] = $defaultControls[$controlKey];
                    $updated = true;
                }
            }

            $defaultContextMenu = Arr::get($defaultPreset, 'settings.context_menu', []);
            if (
                !empty($defaultContextMenu) &&
                Arr::get($presets[$slug], 'settings.context_menu') !== $defaultContextMenu
            ) {
                $presets[$slug]['settings']['context_menu'] = $defaultContextMenu;
                $updated = true;
            }
        }

        if ($updated) {
            update_option(self::OPTION_KEY, wp_json_encode($presets), true);
            static::$cache = $presets;
        }
    }

    /**
     * One-time migration: flp_presets table -> wp_options JSON.
     * Called from DBMigrator during version upgrade. Idempotent.
     */
    public static function maybeMigrateFromTable()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flp_presets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration check
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return; // Already migrated or fresh install
        }

        // Check if already migrated
        $existing = get_option(self::OPTION_KEY);
        if (!empty($existing)) {
            return;
        }

        // 1. Read all presets from old table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE deleted_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (prefix + constant)
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        // 2. Build new option value
        $presets = [];
        $idToSlugMap = [];

        foreach ($rows as $row) {
            $rawSettings = Arr::get($row, 'settings', '');

            // The old Eloquent model used 'array' cast which stores as JSON
            if (is_string($rawSettings)) {
                $settings = json_decode($rawSettings, true);
                if (!is_array($settings)) {
                    // Fallback to PHP unserialization
                    $settings = maybe_unserialize($rawSettings);
                }
            } else {
                $settings = $rawSettings;
            }

            if (!is_array($settings)) {
                $settings = [];
            }
            $slug = Arr::get($settings, 'slug', sanitize_title(Arr::get($row, 'name', 'preset')));

            // Ensure unique slug
            $baseSlug = $slug;
            $i = 1;
            while (Arr::get($presets, $slug)) {
                $slug = $baseSlug . '-' . $i++;
            }

            $presets[$slug] = [
                'name' => Arr::get($row, 'name', ''),
                'slug' => $slug,
                'settings' => $settings,
            ];

            $idToSlugMap[(int) Arr::get($row, 'id', 0)] = $slug;
        }

        // 3. Save all presets as single JSON option
        update_option(self::OPTION_KEY, wp_json_encode($presets), true);
        static::$cache = $presets;

        // 4. Update default preset setting (id -> slug)
        $defaultId = SettingsService::get('general.default_preset_id');
        if ($defaultId && $defaultSlug = Arr::get($idToSlugMap, (int) $defaultId)) {
            SettingsService::updateSetting('general.default_preset', $defaultSlug);
        }

        // 5. Update all media postmeta: preset_id -> preset_slug
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
        $metaRows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = 'settings'
             AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'fluent_player_media')",
            ARRAY_A
        );

        foreach ($metaRows as $meta) {
            $metaSettings = maybe_unserialize(Arr::get($meta, 'meta_value', ''));
            if (!is_array($metaSettings)) {
                continue;
            }

            $presetId = Arr::get($metaSettings, 'preset_id');
            if ($presetId) {
                $slug = Arr::get($idToSlugMap, (int) $presetId, static::getDefaultSlug());
                $metaSettings['preset_slug'] = $slug;
                unset($metaSettings['preset_id']);
                update_post_meta((int) Arr::get($meta, 'post_id'), 'settings', $metaSettings);
            }
        }

        // 6. Update email_collections: convert preset_id integer values to slugs
        $emailTable = $wpdb->prefix . 'flp_email_collections';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration check
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $emailTable)) === $emailTable) {
            // Change column type from bigint to varchar so string slugs can be stored
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time migration
            $wpdb->query("ALTER TABLE `{$emailTable}` MODIFY `preset_id` varchar(255) NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe

            foreach ($idToSlugMap as $oldId => $newSlug) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
                $wpdb->update(
                    $emailTable,
                    ['preset_id' => $newSlug],
                    ['preset_id' => (string) $oldId]
                );
            }
        }

        // 7. Drop old table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time migration cleanup
        $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe
    }

    /**
     * Get the 6 default presets keyed by slug
     */
    protected static function getDefaultPresets()
    {
        $emailCapture = [
            'enabled' => false,
            'percentage' => 0,
            'allow_skip' => false,
            'providers' => [],
            'button_bg_color' => '',
            'button_color' => '',
            'border_radius' => 0,
            'placeholder' => 'Email Address',
            'headline' => 'Sign up to continue watching',
            'bottom_text' => 'We respect your privacy. Unsubscribe at any time.',
            'button_text' => 'Subscribe'
        ];

        $cta = [
            'enabled' => false,
            'percentage' => 80,
            'show_at_end' => false,
            'allow_skip' => true,
            'button_bg_color' => '#DD1F13',
            'button_color' => '#ffffff',
            'border_radius' => 4,
            'headline' => 'Ready to take the next step?',
            'description' => 'Click the button below to learn more.',
            'button_text' => 'Learn More',
            'button_link' => '',
            'open_in_new_tab' => true
        ];

        $actionBar = [
            'enabled' => false,
            'position' => 'bottom',
            'text' => 'Like this?',
            'text_size' => '20',
            'background_color' => 'rgba(0,0,0,0.8)',
            'button_type' => 'none',
            'button_text' => 'Click Here',
            'button_color' => '#DD1F13',
            'button_text_color' => '#ffffff',
            'button_radius' => 4,
            'button_link' => '',
            'open_in_new_tab' => true,
            'percentage_start' => 0,
            'youtube_channel' => '',
            'button_count' => false,
            'show_close' => true,
            'close_button_color' => '#DD1F13',
            'close_button_text_color' => '#ffffff',
        ];

        $defaultBehaviors = [
            'autoplay' => false,
            'muted_autoplay' => false,
            'save_play_position' => false,
            'on_video_end' => 'reset',
            'plays_inline' => true,
            'hide_top_controls' => false,
            'hide_center_controls' => false,
            'hide_bottom_controls' => false,
        ];

        $defaultCaptions = [
            'font_size' => 16,
            'background' => 'rgba(0,0,0,0.8)',
            'color' => '#fff',
        ];

        $presets = [
            'default' => [
                'name' => 'Default',
                'slug' => 'default',
                'description' => __('Full-featured player with all controls and center play button', 'fluent-player'),
                'settings' => [
                    'slug' => 'default',
                    'skin' => 'classic',
                    'controls' => [
                        'backward' => true,
                        'forward' => true,
                        'play' => true,
                        'progress_bar' => true,
                        'current_time' => true,
                        'volume' => true,
                        'settings' => true,
                        'playback_speed' => true,
                        'fullscreen' => true,
                        'pip' => true,
                        'captions_toggle' => true,
                        'chapters' => true,
                    ],
                    'behaviors' => $defaultBehaviors,
                    'styles' => [
                        'captions' => $defaultCaptions,
                        'brand_color' => 'rgba(0,0,0,0.8)',
                    ],
                    'email_capture' => $emailCapture,
                    'cta' => $cta,
                    'action_bar' => $actionBar,
                ],
            ],
            'course' => [
                'name' => 'Modern',
                'slug' => 'course',
                'description' => __('Sleek bottom bar with inline controls for a clean look', 'fluent-player'),
                'settings' => [
                    'skin' => 'modern',
                    'slug' => 'course',
                    'controls' => [
                        'backward' => true,
                        'forward' => true,
                        'play' => true,
                        'progress_bar' => true,
                        'current_time' => true,
                        'volume' => true,
                        'settings' => true,
                        'playback_speed' => true,
                        'fullscreen' => true,
                        'pip' => true,
                        'captions_toggle' => true,
                    ],
                    'behaviors' => array_merge($defaultBehaviors, [
                        'save_play_position' => true,
                    ]),
                    'styles' => [
                        'captions' => $defaultCaptions,
                        'brand_color' => '#DD1F13',
                        'control_bar_color' => '#171717A6',
                    ],
                    'email_capture' => $emailCapture,
                    'cta' => $cta,
                    'action_bar' => $actionBar,
                ],
            ],
            'simple' => [
                'name' => 'Simple',
                'slug' => 'simple',
                'description' => __('Play and progress bar only — minimal distraction', 'fluent-player'),
                'settings' => [
                    'skin' => 'simple',
                    'slug' => 'simple',
                    'controls' => [
                        'backward' => false,
                        'forward' => false,
                        'play' => true,
                        'progress_bar' => true,
                        'current_time' => false,
                        'volume' => false,
                        'settings' => true,
                        'playback_speed' => false,
                        'fullscreen' => true,
                        'pip' => false,
                        'captions_toggle' => true,
                    ],
                    'behaviors' => $defaultBehaviors,
                    'styles' => [
                        'captions' => $defaultCaptions,
                        'brand_color' => 'rgba(0,0,0,0.8)',
                    ],
                    'email_capture' => $emailCapture,
                    'cta' => $cta,
                    'action_bar' => $actionBar,
                ],
            ],
            'standard' => [
                'name' => 'Standard',
                'slug' => 'standard',
                'description' => __('Classic layout with progress bar on top and controls below', 'fluent-player'),
                'settings' => [
                    'slug' => 'standard',
                    'skin' => 'standard',
                    'controls' => [
                        'backward' => true,
                        'forward' => true,
                        'play' => true,
                        'progress_bar' => true,
                        'current_time' => true,
                        'volume' => true,
                        'settings' => true,
                        'playback_speed' => true,
                        'fullscreen' => true,
                        'pip' => true,
                        'captions_toggle' => true,
                        'chapters' => true,
                    ],
                    'behaviors' => array_merge($defaultBehaviors, [
                        'save_play_position' => true,
                    ]),
                    'styles' => [
                        'captions' => $defaultCaptions,
                        'brand_color' => '#DD1F13',
                        'control_bar_color' => '#0E121BB3',
                        'control_bar_blur' => true,
                    ],
                    'email_capture' => $emailCapture,
                    'cta' => $cta,
                    'action_bar' => $actionBar,
                ],
            ],
            'floating' => [
                'name' => 'Floating',
                'slug' => 'floating',
                'description' => __('Controls float over the video with a transparent overlay', 'fluent-player'),
                'settings' => [
                    'slug' => 'floating',
                    'skin' => 'floating',
                    'controls' => [
                        'backward' => false,
                        'forward' => false,
                        'play' => true,
                        'progress_bar' => true,
                        'current_time' => true,
                        'volume' => true,
                        'settings' => true,
                        'playback_speed' => true,
                        'fullscreen' => true,
                        'pip' => false,
                        'captions_toggle' => true,
                        'chapters' => false,
                    ],
                    'behaviors' => $defaultBehaviors,
                    'styles' => [
                        'captions' => $defaultCaptions,
                        'brand_color' => '#DD1F13',
                        'control_bar_color' => '#0E121BB3',
                        'control_bar_blur' => true,
                    ],
                    'email_capture' => $emailCapture,
                    'cta' => $cta,
                    'action_bar' => $actionBar,
                ],
            ],
            'minimal' => [
                'name' => 'Minimal',
                'slug' => 'minimal',
                'description' => __('No visible controls — click to play/pause', 'fluent-player'),
                'settings' => [
                    'slug' => 'minimal',
                    'skin' => 'minimal',
                    'controls' => [
                        'backward' => false,
                        'forward' => false,
                        'play' => false,
                        'progress_bar' => false,
                        'current_time' => false,
                        'volume' => false,
                        'settings' => false,
                        'playback_speed' => false,
                        'fullscreen' => false,
                        'pip' => false,
                        'captions_toggle' => false,
                    ],
                    'behaviors' => $defaultBehaviors,
                    'styles' => [
                        'captions' => $defaultCaptions,
                        'brand_color' => 'rgba(0,0,0,0.8)',
                    ],
                    'email_capture' => $emailCapture,
                    'cta' => $cta,
                    'action_bar' => $actionBar,
                ],
            ],
            'ambient' => [
                'name' => 'Ambient',
                'slug' => 'ambient',
                'description' => __('Muted autoplay loop with no controls — ideal for background video', 'fluent-player'),
                'settings' => [
                    'slug' => 'ambient',
                    'skin' => 'minimal',
                    'controls' => [
                        'backward' => false,
                        'forward' => false,
                        'play' => false,
                        'progress_bar' => false,
                        'current_time' => false,
                        'volume' => false,
                        'settings' => false,
                        'playback_speed' => false,
                        'fullscreen' => false,
                        'pip' => false,
                        'captions_toggle' => false,
                    ],
                    'behaviors' => [
                        'autoplay' => false,
                        'muted_autoplay' => true,
                        'save_play_position' => false,
                        'on_video_end' => 'loop',
                        'plays_inline' => true,
                        'hide_top_controls' => true,
                        'hide_center_controls' => true,
                        'hide_bottom_controls' => true,
                    ],
                    'styles' => [
                        'captions' => $defaultCaptions,
                        'brand_color' => 'rgba(0,0,0,0.8)',
                    ],
                    'email_capture' => $emailCapture,
                    'cta' => $cta,
                    'action_bar' => $actionBar,
                ],
            ],
        ];

        foreach ($presets as $slug => &$preset) {
            $preset['settings'] = self::normalizePresetSettings(
                Arr::get($preset, 'settings', []),
                (string) $slug
            );
        }
        unset($preset);

        return $presets;
    }

    protected static function normalizePresetSettings($settings, $slug = '')
    {
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['context_menu'] = self::normalizeContextMenuSettings($settings, $slug);

        return $settings;
    }

    protected static function normalizeContextMenuSettings($settings, $slug = '')
    {
        $defaults = self::getDefaultContextMenuConfig($settings, $slug);
        $contextMenu = Arr::get($settings, 'context_menu', []);

        if (!is_array($contextMenu)) {
            $contextMenu = [];
        }

        $items = Arr::get($contextMenu, 'items', []);
        if (!is_array($items)) {
            $items = [];
        }

        $isAmbient = self::isAmbientPresetSettings($settings, $slug);
        $normalizedItems = [];

        foreach (Arr::get($defaults, 'items', []) as $key => $defaultValue) {
            $normalizedItems[$key] = $isAmbient
                ? false
                : rest_sanitize_boolean(Arr::get($items, $key, $defaultValue));
        }

        return [
            'enabled' => $isAmbient
                ? false
                : rest_sanitize_boolean(Arr::get($contextMenu, 'enabled', Arr::get($defaults, 'enabled', true))),
            'items' => $normalizedItems,
        ];
    }

    protected static function getDefaultContextMenuConfig($settings, $slug = '')
    {
        if ($slug === 'ambient' || self::isAmbientPresetSettings($settings, $slug)) {
            return [
                'enabled' => false,
                'items' => [
                    'copy_link' => false,
                    'copy_link_at_time' => false,
                    'loop' => false,
                    'playback_speed' => false,
                    'captions' => false,
                    'pip' => false,
                ],
            ];
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            $reservedDefaults = [
                'default' => [
                    'enabled' => true,
                    'items' => [
                        'copy_link' => true,
                        'copy_link_at_time' => true,
                        'loop' => true,
                        'playback_speed' => true,
                        'captions' => true,
                        'pip' => true,
                    ],
                ],
                'course' => [
                    'enabled' => true,
                    'items' => [
                        'copy_link' => true,
                        'copy_link_at_time' => true,
                        'loop' => true,
                        'playback_speed' => true,
                        'captions' => true,
                        'pip' => true,
                    ],
                ],
                'standard' => [
                    'enabled' => true,
                    'items' => [
                        'copy_link' => true,
                        'copy_link_at_time' => true,
                        'loop' => true,
                        'playback_speed' => true,
                        'captions' => true,
                        'pip' => true,
                    ],
                ],
                'floating' => [
                    'enabled' => true,
                    'items' => [
                        'copy_link' => true,
                        'copy_link_at_time' => true,
                        'loop' => true,
                        'playback_speed' => true,
                        'captions' => true,
                        'pip' => false,
                    ],
                ],
                'simple' => [
                    'enabled' => true,
                    'items' => [
                        'copy_link' => true,
                        'copy_link_at_time' => true,
                        'loop' => true,
                        'playback_speed' => false,
                        'captions' => true,
                        'pip' => false,
                    ],
                ],
                'minimal' => [
                    'enabled' => true,
                    'items' => [
                        'copy_link' => true,
                        'copy_link_at_time' => true,
                        'loop' => false,
                        'playback_speed' => false,
                        'captions' => false,
                        'pip' => false,
                    ],
                ],
            ];

            if (isset($reservedDefaults[$slug])) {
                return $reservedDefaults[$slug];
            }
        }

        $controls = Arr::get($settings, 'controls', []);
        $captionsEnabled = Arr::get($controls, 'captions_toggle', true) !== false && Arr::get($settings, 'styles.captions.enabled', true) !== false;

        return [
            'enabled' => true,
            'items' => [
                'copy_link' => true,
                'copy_link_at_time' => true,
                'loop' => true,
                'playback_speed' => Arr::get($controls, 'playback_speed', true) !== false,
                'captions' => $captionsEnabled,
                'pip' => Arr::get($controls, 'pip', true) !== false,
            ],
        ];
    }

    /**
     * Whether a full preset array (slug + settings) is the Pro-only Ambient preset.
     *
     * @param array $preset
     * @return bool
     */
    public static function isAmbientPreset($preset)
    {
        if (!is_array($preset)) {
            return false;
        }

        $slug = Arr::get($preset, 'slug', Arr::get($preset, 'settings.slug', ''));

        return self::isAmbientPresetSettings(Arr::get($preset, 'settings', []), (string) $slug);
    }

    protected static function isAmbientPresetSettings($settings, $slug = '')
    {
        if ($slug === 'ambient') {
            return true;
        }

        return Arr::get($settings, 'skin') === 'minimal'
            && Arr::isTrue($settings, 'behaviors.muted_autoplay')
            && Arr::get($settings, 'behaviors.on_video_end') === 'loop'
            && Arr::isTrue($settings, 'behaviors.hide_top_controls')
            && Arr::isTrue($settings, 'behaviors.hide_center_controls')
            && Arr::isTrue($settings, 'behaviors.hide_bottom_controls');
    }
}
