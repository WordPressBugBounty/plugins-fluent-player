<?php

namespace FluentPlayer\App\Modules\MCP\Tools;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Modules\MCP\Support\ErrorCodes;
use FluentPlayer\App\Modules\MCP\Support\MCPHelper;
use FluentPlayer\App\Modules\MCP\Support\Mutation;
use FluentPlayer\App\Modules\MCP\Support\WriteGuard;
use FluentPlayer\App\Services\PresetService;
use FluentPlayer\App\Services\SettingsService;
use FluentPlayer\Framework\Support\Arr;

/**
 * Global player settings an AI may read and change. subtitle_service, custom_css, licence and
 * integration credentials are deliberately absent.
 */
class SettingsTools
{
    const MAX_SITE_PALETTE = 50;

    const HEX_COLOUR_PATTERN = '^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$';

    public static function definitions()
    {
        $group = __('Global Settings', 'fluent-player');

        return [
            'fluent-player/get-global-settings' => [
                'label'            => __('Get Global Settings', 'fluent-player'),
                'description'      => Helper::hasPro()
                    ? __('Site-wide player defaults: general, YouTube, Vimeo, analytics, Google Analytics and branding (brand colours, logo).', 'fluent-player')
                    : __('Site-wide player defaults: general, YouTube, Vimeo and branding (brand colours, logo). Analytics, Google Analytics, resume playback and hiding "Powered by" need Fluent Player Pro.', 'fluent-player'),
                'group'            => $group,
                'capability'       => 'manage_options',
                'annotations'      => ['readonly' => true],
                'input_schema'     => ['type' => 'object'],
                'execute_callback' => [self::class, 'getGlobalSettings'],
            ],
            'fluent-player/get-site-colors' => [
                'label'            => __('Get Site Colors', 'fluent-player'),
                'description'      => __('The active theme\'s colours as hex codes: its palette, and the background, text, link and button colours the theme sets. Use them to match the player branding to the site.', 'fluent-player'),
                'group'            => $group,
                'capability'       => 'manage_options',
                'annotations'      => ['readonly' => true],
                'input_schema'     => ['type' => 'object'],
                'execute_callback' => [self::class, 'getSiteColors'],
            ],
            'fluent-player/update-global-settings' => [
                'label'            => __('Update Global Settings', 'fluent-player'),
                'description'      => __('Change site-wide player defaults. Send only the sections and keys to change; everything else is kept. Branding here applies to every player that does not use its own branding.', 'fluent-player'),
                'group'            => $group,
                'capability'       => 'manage_options',
                'annotations'      => ['readonly' => false],
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => array_merge(self::sectionSchemas(), WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'updateGlobalSettings'],
            ],
        ];
    }

    /** Pro-only sections and keys are left out on free: saving them there changes nothing on the player. */
    private static function sectionSchemas()
    {
        $colour = ['type' => 'string', 'pattern' => self::HEX_COLOUR_PATTERN];
        $bool = ['type' => 'boolean'];
        $urlOrEmpty = ['type' => 'string', 'anyOf' => [['format' => 'uri'], ['maxLength' => 0]]];
        $section = function (array $properties) {
            return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
        };

        $hasPro = Helper::hasPro();

        $sections = [
            'general'          => $section(array_merge([
                'default_aspect_ratio' => ['type' => 'string', 'enum' => ['original', '16:9', '4:3', '1:1', '9:16', '3:2', '2:3']],
                'default_preset'       => ['type' => 'string'],
                'pause_others_on_play' => $bool,
            ], $hasPro ? ['resume_playback' => $bool] : [])),
            'youtube'          => $section(['privacy_mode' => $bool, 'show_subscribe_button' => $bool]),
            'vimeo'            => $section(['privacy_mode' => $bool]),
            'analytics'        => $section([
                'enabled'      => $bool,
                'auto_cleanup' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => ['enabled' => $bool, 'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730]],
                ],
            ]),
            'google_analytics' => $section([
                'enabled'          => $bool,
                'use_existing_tag' => $bool,
                'measurement_id'   => ['type' => 'string', 'pattern' => '^(G-[A-Z0-9]+)?$'],
            ]),
            'branding'         => $section(array_merge([
                'brand_color'          => $colour,
                'control_bar_color'    => $colour,
                'play_button_color'    => $colour,
                'play_button_bg_color' => $colour,
                'logo_url'             => $urlOrEmpty,
                'logo_link'            => $urlOrEmpty,
                'logo_position'        => ['type' => 'string', 'enum' => ['top-left', 'top-right', 'bottom-left', 'bottom-right']],
                'logo_width'           => ['type' => 'integer', 'minimum' => 20, 'maximum' => 500],
            ], $hasPro ? ['show_powered_by' => $bool] : [])),
        ];

        if (!$hasPro) {
            unset($sections['analytics'], $sections['google_analytics']);
        }

        return $sections;
    }

    /** The readable part of the stored settings: only the whitelisted sections and keys. */
    private static function visibleSettings()
    {
        $all = SettingsService::getSettings();
        $visible = [];
        foreach (self::sectionSchemas() as $section => $schema) {
            $stored = isset($all[$section]) && is_array($all[$section]) ? $all[$section] : [];
            $visible[$section] = array_intersect_key($stored, $schema['properties']);
        }

        return $visible;
    }

    public static function getGlobalSettings($params = [])
    {
        return MCPHelper::envelope(__('Global player settings.', 'fluent-player'), self::visibleSettings());
    }

    public static function getSiteColors($params = [])
    {
        $swatches = [];
        $origins = (array) wp_get_global_settings(['color', 'palette']);
        foreach (['theme', 'custom'] as $origin) {
            foreach (isset($origins[$origin]) ? (array) $origins[$origin] : [] as $swatch) {
                $slug = isset($swatch['slug']) ? (string) $swatch['slug'] : '';
                $hex = self::hexColour(isset($swatch['color']) ? $swatch['color'] : null);
                if (!$hex || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $slug)) {
                    continue;
                }
                $name = isset($swatch['name']) && is_string($swatch['name']) ? mb_substr($swatch['name'], 0, 100) : $slug;
                $swatches[$slug] = ['slug' => $slug, 'name' => MCPHelper::untrusted($name), 'color' => $hex];
            }
        }
        $palette = array_slice(array_values($swatches), 0, self::MAX_SITE_PALETTE);

        $isBlockTheme = wp_is_block_theme();
        $styles = wp_theme_has_theme_json() ? self::themeStyleColors() : [];
        if (!$isBlockTheme && current_theme_supports('custom-background') && $background = sanitize_hex_color_no_hash(get_background_color())) {
            $styles['background'] = '#' . $background;
        }

        $summary = ($palette || $styles)
            /* translators: 1: palette colour count, 2: style colour count */
            ? sprintf(__('%1$d palette colours, %2$d style colours.', 'fluent-player'), count($palette), count($styles))
            : __('No usable site colours found: the theme sets none, or only in forms this tool cannot read (CSS variables, rgb(), hsl()). Ask the user for their brand colour.', 'fluent-player');

        return MCPHelper::envelope($summary, [
            'theme_type' => $isBlockTheme ? 'block' : 'classic',
            'palette'    => $palette,
            'styles'     => $styles,
        ]);
    }

    /** Only paths the theme or user set: core's own defaults (the #32373c button) say nothing about the site. */
    private static function themeStyleColors()
    {
        $paths = [
            'background'        => ['color.background'],
            'text'              => ['color.text'],
            'link'              => ['elements.link.color.text'],
            'button_background' => ['blocks.core/button.color.background', 'elements.button.color.background'],
            'button_text'       => ['blocks.core/button.color.text', 'elements.button.color.text'],
        ];
        $themeData = \WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
        $userData = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
        $resolved = (array) wp_get_global_styles([], ['transforms' => ['resolve-variables']]);

        $colors = [];
        foreach ($paths as $key => $candidates) {
            foreach ($candidates as $path) {
                if (Arr::get($themeData, 'styles.' . $path) === null && Arr::get($userData, 'styles.' . $path) === null) {
                    continue;
                }
                if ($hex = self::hexColour(Arr::get($resolved, $path))) {
                    $colors[$key] = $hex;
                }
                break;
            }
        }

        return $colors;
    }

    private static function hexColour($value)
    {
        return is_string($value) && preg_match('/' . self::HEX_COLOUR_PATTERN . '/D', $value) ? $value : null;
    }

    public static function updateGlobalSettings($params)
    {
        $patch = array_intersect_key($params, self::sectionSchemas());
        if (!$patch) {
            return MCPHelper::error(ErrorCodes::MISSING_PARAM, __('Send at least one settings section to change.', 'fluent-player'));
        }

        if (isset($patch['general']['default_preset'])) {
            $preset = PresetService::find($patch['general']['default_preset']);
            if (!$preset) {
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('That preset does not exist. Use list-presets to see the slugs.', 'fluent-player'), ['field' => 'general.default_preset']);
            }
            if ($proPreset = PresetTools::refuseProPreset($preset, 'general.default_preset')) {
                return $proPreset;
            }
        }

        $current = self::visibleSettings();

        return Mutation::runGuarded(
            'fluent-player/update-global-settings',
            $params,
            'settings:global',
            md5(wp_json_encode($current)),
            function () use ($current, $patch) {
                $changes = [];
                foreach ($patch as $section => $values) {
                    foreach ($values as $key => $value) {
                        $changes[$section . '.' . $key] = [
                            'from' => isset($current[$section][$key]) ? $current[$section][$key] : null,
                            'to'   => $value,
                        ];
                    }
                }

                return ['changes' => $changes];
            },
            function () use ($patch) {
                SettingsService::saveSettings($patch);

                return MCPHelper::envelope(__('Global settings saved.', 'fluent-player'), self::visibleSettings());
            },
            'settings:global'
        );
    }
}
