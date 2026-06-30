<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class SettingsSanitizer
{
    public static function sanitizeMediaSettings($settings)
    {
        if (!is_array($settings)) {
            return [];
        }

        $settings = Sanitizer::sanitize($settings, self::getMediaRules($settings));

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

    protected static function getMediaRules($settings)
    {
        $rules = [
            'title'                        => 'sanitizeTextField',
            'src'                          => 'escUrlRaw',
            'viewType'                     => 'sanitizeTextField',
            'post_status'                  => 'sanitizeTextField',
            'provider'                     => 'sanitizeTextField',
            'posterSrc'                    => 'escUrlRaw',
            'preset_slug'                  => 'sanitizeTextField',
            'playsInline'                  => 'rest_sanitize_boolean',
            'mutedAutoplay'                => 'rest_sanitize_boolean',
            'autoplay'                     => 'rest_sanitize_boolean',
            'loadStrategy'                 => 'sanitizeTextField',
            'preload'                      => 'sanitizeTextField',
            'aspectRatio'                  => 'sanitizeTextField',
            'mediaType'                    => 'sanitizeTextField',
            'streamType'                   => 'sanitizeTextField',
            'save_play_position'           => 'rest_sanitize_boolean',
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
            'bunny_storage.title'          => 'sanitizeTextField',
            'bunny_storage.url'            => 'escUrlRaw',
            'bunny_storage.size'           => 'intval',
            'bunny_storage.updated_at'     => 'sanitizeTextField',
            'bunny_storage.created_at'     => 'sanitizeTextField',
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
        return array_merge([
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
            'layers.*.text'                  => 'sanitizeTextField',
            'layers.*.description'           => 'sanitizeTextField',
            'layers.*.button_text'           => 'sanitizeTextField',
            'layers.*.button_bg_color'       => 'sanitizeTextField',
            'layers.*.button_text_color'     => 'sanitizeTextField',
            'layers.*.url'                   => 'escUrlRaw',
            'layers.*.button_url'            => 'escUrlRaw',
            'layers.*.button_link'           => 'escUrlRaw',
            'layers.*.open_in_new_tab'       => 'rest_sanitize_boolean',
            'layers.*.image_id'              => 'intval',
            'layers.*.image_url'             => 'escUrlRaw',
            'layers.*.image_opacity'         => 'floatval',
            'layers.*.orientation'           => 'sanitizeTextField',
            'layers.*.video_url'             => 'escUrlRaw',
            'layers.*.video_id'              => 'sanitizeTextField',
            'layers.*.skip_offset'           => 'floatval',
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
