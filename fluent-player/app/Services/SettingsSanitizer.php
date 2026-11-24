<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class SettingsSanitizer
{
    const MAX_CLIPS = 50;

    /** Render-time values, never authored; stripped so an editor save cannot persist them. */
    const DERIVED_RENDER_KEYS = [
        'signed_url_expires_at',
        'bunny_token_query',
    ];

    public static function sanitizeMediaSettings($settings)
    {
        if (!is_array($settings)) {
            return [];
        }

        // `clip` is resolved at render time; only `clips[]` is stored.
        unset($settings['clip']);
        if (isset($settings['clips'])) {
            $clips = is_array($settings['clips']) ? array_slice(array_values(array_filter($settings['clips'], 'is_array')), 0, self::MAX_CLIPS) : [];
            $settings['clips'] = array_map(function ($clip) {
                return [
                    'id'      => Arr::get($clip, 'id', ''),
                    'label'   => Arr::get($clip, 'label', ''),
                    'start'   => Arr::get($clip, 'start', 0),
                    'end'     => Arr::get($clip, 'end', 0),
                    'enabled' => Arr::get($clip, 'enabled', true),
                ];
            }, $clips);
        }

        $settings = Sanitizer::sanitize($settings, self::getMediaRules($settings));

        self::normalizeVolumeOverride($settings);

        // The REST response carries filter-signed settings and sanitize() passes unknown
        // keys through, so these ride back in on save. A stored expiry is always older
        // than a fresh one, so it would win forever.
        foreach (self::DERIVED_RENDER_KEYS as $derivedKey) {
            unset($settings[$derivedKey]);
        }

        if (!empty($settings['mutedPreview'])) {
            $settings['autoplay'] = false;
            $settings['mutedAutoplay'] = false;
        }

        return self::normalizeConditions($settings);
    }

    public static function sanitizePresetSettings($settings)
    {
        if (!is_array($settings)) {
            return [];
        }

        $settings = Sanitizer::sanitize($settings, self::getPresetRules());

        return self::normalizeConditions($settings);
    }

    /** Drop a non-numeric/blank override so the media inherits the global default instead of muting at 0. */
    protected static function normalizeVolumeOverride(&$settings)
    {
        if (!array_key_exists('volume', $settings)) {
            return;
        }

        $value = $settings['volume'];
        if ($value === '' || $value === null || !is_numeric($value)) {
            unset($settings['volume']);
            return;
        }

        $settings['volume'] = max(0, min(100, intval($value)));
    }

    protected static function getMediaRules($settings)
    {
        $clipTime = function ($value) {
            $seconds = is_scalar($value) ? (float) $value : 0.0;
            return is_finite($seconds) ? min(max(0.0, $seconds), 604800.0) : 0.0;
        };

        $rules = [
            'title'                        => 'sanitizeTextField',
            'src'                          => 'escUrlRaw',
            'viewType'                     => 'sanitizeTextField',
            'post_status'                  => 'sanitizeTextField',
            'provider'                     => 'sanitizeTextField',
            'posterSrc'                    => 'escUrlRaw',
            'preset_slug'                  => 'sanitizeTextField',
            'playsInline'                  => 'rest_sanitize_boolean',
            'webmcp'                       => function ($value) {
                return \FluentPlayer\App\Modules\AgentReady\WebMCP::isSwitchOn($value);
            },
            'mutedAutoplay'                => 'rest_sanitize_boolean',
            'mutedPreview'                        => 'rest_sanitize_boolean',
            'mutedPreviewConfig.captions'         => 'rest_sanitize_boolean',
            'mutedPreviewConfig.volume'           => 'intval',
            'mutedPreviewConfig.showOverlays'     => 'rest_sanitize_boolean',
            'mutedPreviewConfig.showLogo'         => 'rest_sanitize_boolean',
            'mutedPreviewConfig.clickBehavior'    => 'sanitizeKey',
            'mutedPreviewConfig.range.start'      => 'floatval',
            'mutedPreviewConfig.range.end'        => 'floatval',
            'mutedPreviewConfig.overlay.type'     => 'sanitizeTextField',
            'mutedPreviewConfig.overlay.src'      => 'escUrlRaw',
            'mutedPreviewConfig.overlay.text'     => 'sanitizeTextField',
            'mutedPreviewConfig.overlay.width'    => 'intval',
            'mutedPreviewConfig.overlay.opacity'  => 'intval',
            'mutedPreviewConfig.overlay.position' => 'sanitizeTextField',
            'autoplay'                     => 'rest_sanitize_boolean',
            'loadStrategy'                 => 'sanitizeTextField',
            'preload'                      => 'sanitizeTextField',
            'aspectRatio'                  => 'sanitizeTextField',
            'mediaType'                    => 'sanitizeTextField',
            'streamType'                   => 'sanitizeTextField',
            'save_play_position'           => 'rest_sanitize_boolean',
            'share_enabled'                => 'rest_sanitize_boolean',
            'duration'                     => 'floatval',
            'attachment_id'                => 'intval',
            'description'                  => 'ksesPost',
            'useCustomBranding'            => 'rest_sanitize_boolean',
            'brandingColor'                => 'sanitizeTextField',
            'controlBarColor'              => 'sanitizeTextField',
            'playButtonColor'              => 'sanitizeTextField',
            'playButtonBgColor'            => 'sanitizeTextField',
            'logo'                         => 'sanitizeTextField',
            'logoLink'                     => 'escUrlRaw',
            'logoWidth'                    => 'intval',
            'logoPosition'                 => 'sanitizeTextField',
            'chapters.*.title'             => 'sanitizeTextField',
            'chapters.*.startTime'         => 'floatval',
            'chapters.*.endTime'           => 'floatval',
            'chaptersDisplay'              => 'sanitizeTextField',
            'clips_enabled'                => 'rest_sanitize_boolean',
            'clips.*.id'                   => 'sanitizeTextField',
            'clips.*.label'                => 'sanitizeTextField',
            'clips.*.start'                => $clipTime,
            'clips.*.end'                  => $clipTime,
            'clips.*.enabled'              => 'rest_sanitize_boolean',
            'overlays.*.text'              => 'sanitizeTextField',
            'overlays.*.id'                => 'sanitizeTextField',
            'overlays.*.link'              => 'escUrlRaw',
            'overlays.*.backgroundColor'   => 'sanitizeTextField',
            'overlays.*.textColor'         => 'sanitizeTextField',
            'overlays.*.position'          => 'sanitizeTextField',
            'overlays.*.displayTime'       => 'floatval',
            'overlays.*.duration'          => 'intval',
            'overlays.*.dynamic_position'  => 'rest_sanitize_boolean',
            'overlays.*.reposition_interval' => 'intval',
            'overlays.*.reposition_animation' => 'sanitizeTextField',
            'video_end_option'             => 'sanitizeTextField',
            'language'                     => 'sanitizeTextField',
            'language_mappings.*.language' => 'sanitizeTextField',
            'language_mappings.*.media_id' => 'intval',
            'language_mappings.*.id'       => 'intval',
            'show_language_switcher'       => 'rest_sanitize_boolean',
            'show_title_overlay'           => 'rest_sanitize_boolean',
            'show_poster'                  => 'rest_sanitize_boolean',
            'mini_player_enabled'          => 'rest_sanitize_boolean',
            'mini_player_bottom_bar'       => 'rest_sanitize_boolean',
            'poster_is_custom'             => 'rest_sanitize_boolean',
            'subtitles.*.id'               => 'sanitizeTextField',
            'subtitles.*.attachment_id'    => 'intval',
            'subtitles.*.url'              => 'escUrlRaw',
            'subtitles.*.filename'         => 'sanitizeTextField',
            'subtitles.*.language'         => 'sanitizeTextField',
            'subtitles.*.label'            => 'sanitizeTextField',
            'subtitles.*.is_default'       => 'rest_sanitize_boolean',
            'bunny.library_id'             => 'sanitizeTextField',
            'bunny.video_id'               => 'sanitizeTextField',
            'bunny.collection_id'          => 'sanitizeTextField',
            'bunny.title'                  => 'sanitizeTextField',
            'bunny.thumbnail'              => 'escUrlRaw',
            'bunny.duration'               => 'floatval',
            'bunny.token_auth_enabled'     => 'rest_sanitize_boolean',
            'bunny.mp4_urls.*'             => 'escUrlRaw',
            'mux.asset_id'                 => 'sanitizeTextField',
            'mux.playback_id'              => 'sanitizeTextField',
            'mux.thumbnail'                => 'escUrlRaw',
            'mux.duration'                 => 'floatval',
            'mux.status'                   => 'sanitizeTextField',
            'mux.max_resolution'           => 'sanitizeTextField',
            'mux.aspect_ratio'             => 'sanitizeTextField',
            'mux.live_stream_id'           => 'sanitizeTextField',
            'mux.is_live'                  => 'rest_sanitize_boolean',
            'mux.playback_policy'          => 'sanitizeTextField',
            'mux.playback_token'           => function ($value) {
                return self::sanitizeMuxPlaybackToken($value);
            },
            'bunny_storage.file_path'      => 'sanitizeTextField',
            // Which storage zone the file came from. Media payloads are client-supplied
            // and the sanitizer keeps fields it has no rule for, so without this a
            // non-string could be persisted and later trimmed as if it were one.
            'bunny_storage.zone'           => 'sanitizeTextField',
            'bunny_storage.title'          => 'sanitizeTextField',
            'bunny_storage.url'            => 'escUrlRaw',
            'bunny_storage.size'           => 'intval',
            'bunny_storage.updated_at'     => 'sanitizeTextField',
            'bunny_storage.created_at'     => 'sanitizeTextField',
            'r2.key'                       => 'sanitizeTextField',
            'r2.bucket'                    => 'sanitizeTextField',
            'r2.size'                      => 'intval',
            'stream.uid'                   => 'sanitizeTextField',
            'stream.hls'                   => 'escUrlRaw',
            'stream.thumbnail'             => 'escUrlRaw',
            'stream.duration'              => 'floatval',
            'stream.status'                => 'sanitizeTextField',
            'stream.ready'                 => 'rest_sanitize_boolean',
            'stream.require_signed_urls'   => 'rest_sanitize_boolean',
            'stream.playback_token'        => function ($value) {
                return is_string($value) ? preg_replace('/[^A-Za-z0-9._-]/', '', $value) : '';
            },
        ];

        if (!empty($settings['layers']) && is_array($settings['layers'])) {
            $rules = array_merge($rules, self::getLayerRules());
        }

        return $rules;
    }

    protected static function getPresetRules()
    {
        return array_merge(
            self::getContextMenuRules('context_menu'),
            self::getEmailCaptureRules('email_capture'),
            self::getCtaRules('cta'),
            self::getActionBarRules('action_bar')
        );
    }

    protected static function getContextMenuRules($prefix)
    {
        return [
            $prefix . '.enabled' => 'rest_sanitize_boolean',
            $prefix . '.items.copy_link' => 'rest_sanitize_boolean',
            $prefix . '.items.copy_link_at_time' => 'rest_sanitize_boolean',
            $prefix . '.items.loop' => 'rest_sanitize_boolean',
            $prefix . '.items.playback_speed' => 'rest_sanitize_boolean',
            $prefix . '.items.captions' => 'rest_sanitize_boolean',
            $prefix . '.items.pip' => 'rest_sanitize_boolean',
        ];
    }

    protected static function getLayerRules()
    {
        $rules = array_merge([
            'layers.*.id'                    => 'sanitizeTextField',
            'layers.*.condition_state_key'   => 'sanitizeTextField',
            'layers.*.type'                  => 'sanitizeTextField',
            'layers.*.title'                 => 'sanitizeTextField',
            'layers.*.displayTime'           => 'floatval',
            'layers.*.duration'              => 'floatval',
            'layers.*.pauseOnHover'          => 'rest_sanitize_boolean',
            'layers.*.bg_color'              => 'sanitizeTextField',
            'layers.*.text_color'            => 'sanitizeTextField',
            'layers.*.allow_skip'            => 'rest_sanitize_boolean',
            'layers.*.width'                 => 'intval',
            'layers.*.height'                => 'intval',
            'layers.*.position'              => 'sanitizeTextField',
            'layers.*.cta_type'              => 'sanitizeTextField',
            'layers.*.content'               => 'ksesPost',
            'layers.*.completion_type'       => 'sanitizeTextField',
            'layers.*.auto_dismiss_duration' => 'intval',
            'layers.*.button_text'           => 'sanitizeTextField',
            'layers.*.media_url'             => 'escUrlRaw',
            'layers.*.media_id'              => 'intval',
            'layers.*.media_type'            => 'sanitizeTextField',
            'layers.*.video_url'             => 'escUrlRaw',
            'layers.*.video_id'              => 'sanitizeTextField',
            'layers.*.media_url'             => 'escUrlRaw',
            'layers.*.media_type'            => 'sanitizeTextField',
            'layers.*.media_id'              => 'intval',
            // Text not escUrlRaw so {{shortcode}} braces survive; esc_url() applied at render.
            'layers.*.click_url'             => 'sanitizeTextField',
            'layers.*.badge_text'            => 'sanitizeTextField',
            'layers.*.skip_offset'           => 'floatval',
            'layers.*.sound_mode'            => 'sanitizeTextField',
            'layers.*.fill_bar_color'        => 'sanitizeTextField',
            'layers.*.form_type'             => 'sanitizeTextField',
            'layers.*.form_id'               => 'intval',
            'layers.*.hotspots.*.id'         => 'sanitizeTextField',
            'layers.*.hotspots.*.tooltip_text' => 'sanitizeTextField',
            'layers.*.hotspots.*.link'       => 'escUrlRaw',
            'layers.*.hotspots.*.type'       => 'sanitizeTextField',
            'layers.*.hotspots.*.background_color' => 'sanitizeTextField',
            'layers.*.hotspots.*.icon'       => 'sanitizeTextField',
            'layers.*.hotspots.*.position_x' => 'floatval',
            'layers.*.hotspots.*.position_y' => 'floatval',
            'layers.*.hotspots.*.size'       => 'intval',
            'layers.*.shortcode'             => 'sanitizeTextField',
        ],
            self::getConditionRules('layers.*.conditionals'),
            self::getEmailCaptureRules('layers.*.email_capture', false)
        );

        // Integrations add rules for their own layer fields; core's rules always stay.
        // A key with no rule is saved unsanitized, so integrations register these
        // whether or not their plugin is active.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return array_merge((array) apply_filters('fluent_player/layer_sanitize_rules', []), $rules);
    }

    protected static function getEmailCaptureRules($prefix, $includeConditionRules = true)
    {
        $rules = [
            $prefix . '.enabled'             => 'rest_sanitize_boolean',
            $prefix . '.percentage'          => 'floatval',
            $prefix . '.allow_skip'          => 'rest_sanitize_boolean',
            $prefix . '.button_bg_color'     => 'sanitizeTextField',
            $prefix . '.button_color'        => 'sanitizeTextField',
            $prefix . '.border_radius'       => 'intval',
            $prefix . '.placeholder'         => 'sanitizeTextField',
            $prefix . '.headline'            => 'sanitizeTextField',
            $prefix . '.bottom_text'         => 'sanitizeTextField',
            $prefix . '.button_text'         => 'sanitizeTextField',
            $prefix . '.confirmation_message' => 'sanitizeTextField',
            $prefix . '.confirmation_countdown' => 'intval',
            $prefix . '.confirmation_dismiss_text' => 'sanitizeTextField',
            $prefix . '.providers.*.enabled' => 'rest_sanitize_boolean',
            $prefix . '.providers.*.type'    => 'sanitizeTextField',
            $prefix . '.providers.*.config.email_subject' => 'sanitizeTextField',
            $prefix . '.providers.*.config.email_body' => 'escTextarea',
            $prefix . '.providers.*.config.api_key' => 'sanitizeTextField',
            $prefix . '.providers.*.config.list_id' => 'sanitizeTextField',
            $prefix . '.providers.*.config.webhook_id' => 'sanitizeTextField',
            $prefix . '.providers.*.config.lists.*' => 'sanitizeTextField',
            $prefix . '.providers.*.config.tags' => 'sanitizeTextField',
            $prefix . '.providers.*.config.attachments.*.id' => 'intval',
            $prefix . '.providers.*.config.attachments.*.url' => 'escUrlRaw',
            $prefix . '.providers.*.config.attachments.*.name' => 'sanitizeTextField',
            $prefix . '.providers.*.config.attachments.*.type' => 'sanitizeTextField',
        ];

        if ($includeConditionRules) {
            $rules = array_merge($rules, self::getConditionRules($prefix . '.conditionals'));
        }

        return $rules;
    }

    protected static function getCtaRules($prefix)
    {
        return array_merge([
            $prefix . '.enabled'             => 'rest_sanitize_boolean',
            $prefix . '.percentage'          => 'floatval',
            $prefix . '.content'             => 'ksesPost',
            $prefix . '.completion_type'     => 'sanitizeTextField',
            $prefix . '.auto_dismiss_duration' => 'intval',
            $prefix . '.allow_skip'          => 'rest_sanitize_boolean',
            $prefix . '.bg_color'            => 'sanitizeTextField',
            $prefix . '.text_color'          => 'sanitizeTextField',
        ], self::getConditionRules($prefix . '.conditionals'));
    }

    protected static function getActionBarRules($prefix)
    {
        return [
            $prefix . '.enabled'             => 'rest_sanitize_boolean',
            $prefix . '.position'            => 'sanitizeTextField',
            $prefix . '.text'                => 'sanitizeTextField',
            $prefix . '.text_size'           => 'sanitizeTextField',
            $prefix . '.background_color'    => 'sanitizeTextField',
            $prefix . '.button_type'         => 'sanitizeTextField',
            $prefix . '.button_text'         => 'sanitizeTextField',
            $prefix . '.button_color'        => 'sanitizeTextField',
            $prefix . '.button_text_color'   => 'sanitizeTextField',
            $prefix . '.button_radius'       => 'intval',
            $prefix . '.button_link'         => 'escUrlRaw',
            $prefix . '.open_in_new_tab'     => 'rest_sanitize_boolean',
            $prefix . '.percentage_start'    => 'floatval',
            $prefix . '.youtube_channel'     => 'sanitizeTextField',
            $prefix . '.button_count'        => 'rest_sanitize_boolean',
            $prefix . '.subscriber_count'    => 'sanitizeTextField',
            $prefix . '.show_close'          => 'rest_sanitize_boolean',
            $prefix . '.close_button_color'  => 'sanitizeTextField',
            $prefix . '.close_button_text_color' => 'sanitizeTextField',
        ];
    }

    protected static function getConditionRules($prefix)
    {
        return [
            $prefix . '.enabled'             => 'rest_sanitize_boolean',
            $prefix . '.match'               => 'sanitizeTextField',
            $prefix . '.rules.*.field'       => 'sanitizeTextField',
            $prefix . '.rules.*.key'         => function ($value) {
                return self::sanitizeConditionRuleKey($value);
            },
            $prefix . '.rules.*.operator'    => 'sanitizeTextField',
            $prefix . '.rules.*.value'       => function ($value) {
                return self::sanitizeConditionRuleValue($value);
            },
        ];
    }

    protected static function normalizeConditions($settings)
    {
        if (!is_array($settings)) {
            return [];
        }

        $emailCaptureConditionals = Arr::get($settings, 'email_capture.conditionals', []);
        if (
            $emailCaptureConditionals &&
            is_array($emailCaptureConditionals) &&
            Helper::hasPro() &&
            class_exists('\FluentPlayerPro\App\Services\ConditionService')
        ) {
            $settings['email_capture']['conditionals'] = \FluentPlayerPro\App\Services\ConditionService::normalizeConditionals($emailCaptureConditionals);
        }

        $ctaConditionals = Arr::get($settings, 'cta.conditionals', []);
        if (
            $ctaConditionals &&
            is_array($ctaConditionals) &&
            Helper::hasPro() &&
            class_exists('\FluentPlayerPro\App\Services\ConditionService')
        ) {
            $settings['cta']['conditionals'] = \FluentPlayerPro\App\Services\ConditionService::normalizeConditionals($ctaConditionals);
        }

        if (!empty($settings['layers']) && is_array($settings['layers'])) {
            foreach ($settings['layers'] as $index => $layer) {
                if (!is_array($layer)) {
                    continue;
                }

                if (
                    Helper::hasPro() &&
                    class_exists('\FluentPlayerPro\App\Services\ConditionService')
                ) {
                    $settings['layers'][$index]['conditionals'] = \FluentPlayerPro\App\Services\ConditionService::normalizeConditionals(
                        Arr::get($layer, 'conditionals', [])
                    );
                }

            }
        }

        return $settings;
    }

    public static function sanitizeConditionRuleValue($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return '';
        }

        return Sanitizer::sanitizeTextField((string) $value);
    }

    public static function sanitizeConditionRuleKey($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $key = trim((string) $value);
        if ($key === '') {
            return '';
        }

        $key = preg_replace('/[^A-Za-z0-9_.-]/', '', $key);

        return is_string($key) ? substr($key, 0, 64) : '';
    }

    public static function sanitizeMuxPlaybackToken($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $token = trim((string) $value);
        if ($token === '') {
            return '';
        }

        if (strlen($token) > 8192) {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token)) {
            return '';
        }

        return $token;
    }
}
