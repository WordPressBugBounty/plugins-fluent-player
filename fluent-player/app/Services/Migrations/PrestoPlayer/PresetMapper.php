<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

/**
 * Maps Presto Player preset data to Fluent Player preset format.
 * No database access — all methods are stateless transformers.
 */
class PresetMapper
{
    /**
     * Map a Presto Player preset to Fluent Player preset format
     *
     * @param object $ppPreset Raw PP preset row from DB
     * @param bool $isAudio Whether this is an audio preset
     * @return array FP preset structure
     */
    public static function map($ppPreset, $isAudio = false)
    {
        $slug = self::generateSlug($ppPreset->name ?? 'imported');

        return [
            'name'        => Sanitizer::sanitizeTextField($ppPreset->name ?? __('Imported Preset', 'fluent-player')),
            'slug'        => $slug,
            'description' => sprintf(
                /* translators: %s: preset kind, either "audio" or "video". */
                __('Imported from Presto Player %s preset', 'fluent-player'),
                $isAudio ? 'audio' : 'video'
            ),
            'settings'    => [
                'slug'          => $slug,
                'skin'          => self::mapSkin($ppPreset->skin ?? ''),
                'controls'      => self::mapControls($ppPreset, $isAudio),
                'behaviors'     => self::mapBehaviors($ppPreset),
                'styles'        => self::mapStyles($ppPreset),
                'email_capture' => self::mapEmailCapture($ppPreset),
                'cta'           => self::mapCta($ppPreset),
                'action_bar'    => self::mapActionBar($ppPreset),
            ],
        ];
    }

    /**
     * @param object $ppPreset
     * @param bool $isAudio
     * @return array
     */
    private static function mapControls($ppPreset, $isAudio = false)
    {
        $get = function ($field) use ($ppPreset) {
            return !empty($ppPreset->$field);
        };

        return [
            'backward'        => $get('rewind'),
            'forward'         => $get('fast-forward'),
            'play'            => $get('play'),
            'progress_bar'    => $get('progress'),
            'current_time'    => $get('current-time'),
            'volume'          => $get('mute') || $get('volume'),
            'settings'        => false,
            'playback_speed'  => $get('speed'),
            'fullscreen'      => !$isAudio && $get('fullscreen'),
            'pip'             => !$isAudio && $get('pip'),
            'captions_toggle' => !$isAudio && $get('captions'),
            'chapters'        => true,
        ];
    }

    /**
     * @param object $ppPreset
     * @return array
     */
    private static function mapBehaviors($ppPreset)
    {
        $onVideoEnd = 'reset';
        if (!empty($ppPreset->on_video_end)) {
            $ppEnd = $ppPreset->on_video_end;
            if ($ppEnd === 'restart' || $ppEnd === 'loop') {
                $onVideoEnd = 'loop';
            }
        } elseif (!empty($ppPreset->reset_on_end)) {
            $onVideoEnd = 'reset';
        }

        return [
            'autoplay'              => false,
            'muted_autoplay'        => false,
            'save_play_position'    => !empty($ppPreset->save_player_position),
            'on_video_end'          => $onVideoEnd,
            'plays_inline'          => true,
            'hide_top_controls'     => false,
            'hide_center_controls'  => false,
            'hide_bottom_controls'  => false,
        ];
    }

    /**
     * @param object $ppPreset
     * @return array
     */
    private static function mapStyles($ppPreset)
    {
        $styles = [
            'captions' => [
                'font_size'  => 16,
                'background' => 'rgba(0,0,0,0.8)',
                'color'      => '#fff',
            ],
        ];

        if (!empty($ppPreset->caption_background)) {
            $styles['captions']['background'] = Sanitizer::sanitizeTextField($ppPreset->caption_background);
        }

        if (!empty($ppPreset->background_color)) {
            $styles['brand_color'] = Sanitizer::sanitizeTextField($ppPreset->background_color);
        }

        return $styles;
    }

    /**
     * @param object $ppPreset
     * @return array
     */
    private static function mapEmailCapture($ppPreset)
    {
        $data = FieldMapper::decodeJsonField($ppPreset, 'email_collection');

        if (empty($data) || empty(Arr::get($data, 'enabled'))) {
            return ['enabled' => false, 'percentage' => 0, 'allow_skip' => false];
        }

        $providers = [];
        $ppProvider = Sanitizer::sanitizeKey(Arr::get($data, 'provider', ''));
        $ppList = Arr::get($data, 'provider_list', '');
        $ppTag = Arr::get($data, 'provider_tag', '');

        if ($ppProvider && $ppProvider !== 'none') {
            $fpType = $ppProvider;
            if ($ppProvider === 'webhooks') {
                $fpType = 'webhook';
            }

            $config = [];
            if ($ppList) {
                $config['lists'] = is_array($ppList) ? $ppList : [$ppList];
            }
            if ($ppTag) {
                $config['tags'] = $ppTag;
            }

            $providers[] = [
                'enabled' => true,
                'type'    => $fpType,
                'config'  => $config,
            ];
        }

        return [
            'enabled'             => true,
            'percentage'          => absint(Arr::get($data, 'percentage', 0)),
            'allow_skip'          => !empty(Arr::get($data, 'allow_skip')),
            'providers'           => $providers,
            'button_bg_color'     => Sanitizer::sanitizeTextField(Arr::get($data, 'button_color', '#4e9cf6')),
            'button_color'        => Sanitizer::sanitizeTextField(Arr::get($data, 'button_text_color', '#ffffff')),
            'border_radius'       => absint(Arr::get($data, 'border_radius', 4)),
            'placeholder'         => __('Email Address', 'fluent-player'),
            'headline'            => Sanitizer::sanitizeTextField(Arr::get($data, 'headline', '')),
            'bottom_text'         => Sanitizer::sanitizeTextField(Arr::get($data, 'bottom_text', '')),
            'button_text'         => Sanitizer::sanitizeTextField(Arr::get($data, 'button_text', __('Subscribe', 'fluent-player'))),
        ];
    }

    /**
     * @param object $ppPreset
     * @return array
     */
    private static function mapCta($ppPreset)
    {
        $data = FieldMapper::decodeJsonField($ppPreset, 'cta');

        if (empty($data) || empty(Arr::get($data, 'enabled'))) {
            return ['enabled' => false, 'percentage' => 80, 'allow_skip' => true];
        }

        $headline = Sanitizer::sanitizeTextField(Arr::get($data, 'headline', ''));
        $bottomText = Sanitizer::sanitizeTextField(Arr::get($data, 'bottom_text', ''));
        $buttonText = Sanitizer::sanitizeTextField(Arr::get($data, 'button_text', ''));
        $buttonLinkData = Arr::get($data, 'button_link', []);
        $buttonUrl = is_array($buttonLinkData)
            ? Sanitizer::escUrlRaw(Arr::get($buttonLinkData, 'url', ''))
            : Sanitizer::escUrlRaw($buttonLinkData);
        $openNewTab = is_array($buttonLinkData) && !empty(Arr::get($buttonLinkData, 'opensInNewTab'));

        $buttonBgColor = Sanitizer::sanitizeTextField(Arr::get($data, 'button_color', '')) ?: '#4e9cf6';
        $buttonTextColor = Sanitizer::sanitizeTextField(Arr::get($data, 'button_text_color', '')) ?: '#ffffff';
        $buttonRadius = absint(Arr::get($data, 'button_radius', 4));
        $showButton = !empty(Arr::get($data, 'show_button'));

        $content = '';
        if ($headline) {
            $content .= '<h2 style="text-align:center">' . $headline . '</h2>';
        }
        if ($bottomText) {
            $content .= '<p style="text-align:center">' . $bottomText . '</p>';
        }
        if ($showButton && $buttonText) {
            $href = $buttonUrl ? ' href="' . $buttonUrl . '"' : ' href="#"';
            $target = $openNewTab ? ' target="_blank"' : '';
            $content .= '<p style="text-align:center"><a' . $href . $target . ' style="background-color:' . $buttonBgColor . ';color:' . $buttonTextColor . ';display:inline-block;padding:12px 24px;text-decoration:none;border-radius:' . $buttonRadius . 'px;font-weight:500">' . $buttonText . '</a></p>';
        }

        return [
            'enabled'         => true,
            'percentage'      => absint(Arr::get($data, 'percentage', 80)),
            'allow_skip'      => !empty(Arr::get($data, 'show_skip')),
            'content'         => $content,
            'bg_color'        => '#0E121B',
            'text_color'      => '#ffffff',
            'completion_type' => 'skip_only',
        ];
    }

    /**
     * @param object $ppPreset
     * @return array
     */
    private static function mapActionBar($ppPreset)
    {
        $actionBarData = FieldMapper::decodeJsonField($ppPreset, 'action_bar');

        if (empty($actionBarData) || empty(Arr::get($actionBarData, 'enabled'))) {
            return ['enabled' => false];
        }

        $buttonLinkData = Arr::get($actionBarData, 'button_link', []);
        $buttonUrl = is_array($buttonLinkData)
            ? Sanitizer::escUrlRaw(Arr::get($buttonLinkData, 'url', ''))
            : Sanitizer::escUrlRaw($buttonLinkData);
        $openNewTab = is_array($buttonLinkData)
            ? !empty(Arr::get($buttonLinkData, 'opensInNewTab'))
            : false;

        $ppButtonType = Sanitizer::sanitizeKey(Arr::get($actionBarData, 'button_type', ''));
        $buttonType = 'none';
        if ($ppButtonType === 'youtube') {
            $buttonType = 'youtube';
        } elseif ($buttonUrl) {
            $buttonType = 'custom';
        }

        return [
            'enabled'                 => true,
            'position'                => 'bottom',
            'text'                    => Sanitizer::sanitizeTextField(Arr::get($actionBarData, 'text', '')),
            'text_size'               => '20',
            'background_color'        => Sanitizer::sanitizeTextField(Arr::get($actionBarData, 'background_color', 'rgba(0,0,0,0.8)')),
            'button_type'             => $buttonType,
            'button_text'             => Sanitizer::sanitizeTextField(Arr::get($actionBarData, 'button_text', '')),
            'button_color'            => Sanitizer::sanitizeTextField(Arr::get($actionBarData, 'button_color', '#4e9cf6')),
            'button_text_color'       => Sanitizer::sanitizeTextField(Arr::get($actionBarData, 'button_text_color', '#ffffff')),
            'button_radius'           => absint(Arr::get($actionBarData, 'button_radius', 4)),
            'button_link'             => $buttonUrl,
            'open_in_new_tab'         => $openNewTab,
            'text_color'              => '#ffffff',
            'percentage_start'        => absint(Arr::get($actionBarData, 'percentage_start', 0)),
            'youtube_channel'         => '',
            'button_count'            => !empty(Arr::get($actionBarData, 'button_count')),
            'subscriber_count'        => '',
            'show_close'              => true,
            'close_button_color'      => '#4e9cf6',
            'close_button_text_color' => '#ffffff',
        ];
    }

    /**
     * @param string $ppSkin
     * @return string
     */
    private static function mapSkin($ppSkin)
    {
        $skinMap = [
            'playDark'  => 'classic',
            'playLight' => 'classic',
            'modern'    => 'modern',
            'stacked'   => 'standard',
        ];

        return $skinMap[$ppSkin] ?? 'classic';
    }

    /**
     * @param string $name
     * @return string
     */
    private static function generateSlug($name)
    {
        $slug = Sanitizer::sanitizeTitle('pp-' . $name);
        return substr($slug, 0, 50);
    }
}
