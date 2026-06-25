<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

/**
 * Maps Presto Player media/global data to Fluent Player format.
 * No database access — all methods are stateless transformers.
 */
class FieldMapper
{
    /**
     * Provider mapping: PP block name → FP viewType + provider
     */
    private static $providerMap = [
        'presto-player/self-hosted' => ['viewType' => 'video', 'provider' => 'wordpress'],
        'presto-player/youtube'     => ['viewType' => 'video', 'provider' => 'youtube'],
        'presto-player/vimeo'       => ['viewType' => 'video', 'provider' => 'vimeo'],
        'presto-player/audio'       => ['viewType' => 'audio', 'provider' => 'wordpress'],
        'presto-player/bunny'       => ['viewType' => 'video', 'provider' => 'bunny'],
    ];

    /**
     * Map PP block attributes to FP media settings
     *
     * @param array $attrs PP block attributes
     * @param string $blockName PP block name (e.g., 'presto-player/youtube')
     * @param string|null $presetSlug Mapped FP preset slug
     * @return array FP media settings
     */
    public static function mapMediaSettings($attrs, $blockName, $presetSlug = null)
    {
        $providerInfo = Arr::get(self::$providerMap, $blockName, ['viewType' => 'video', 'provider' => 'wordpress']);
        $isAudio = $providerInfo['viewType'] === 'audio';

        $duration = 0;
        $attachmentId = absint(Arr::get($attrs, 'attachment_id', 0));
        if ($attachmentId) {
            $duration = self::getDurationFromAttachment($attachmentId);
        }

        $settings = [
            'viewType'              => $providerInfo['viewType'],
            'provider'              => $providerInfo['provider'],
            'src'                   => Sanitizer::escUrlRaw(Arr::get($attrs, 'src', '')),
            'posterSrc'             => Sanitizer::escUrlRaw(Arr::get($attrs, 'poster', '')),
            'preset_slug'           => $presetSlug ?: 'course',
            'chapters'              => self::mapChapters(Arr::get($attrs, 'chapters', []), $duration),
            'overlays'              => self::mapOverlays(Arr::get($attrs, 'overlays', [])),
            'language_mappings'     => [],
            'show_language_switcher' => false,
            'loadStrategy'          => 'visible',
            'mediaType'             => $isAudio ? 'audio' : 'video',
            'streamType'            => 'on-demand',
        ];

        if ($attachmentId) {
            $settings['attachment_id'] = $attachmentId;
            if ($duration > 0) {
                $settings['duration'] = $duration;
            }
        }

        $aspectRatio = Arr::get($attrs, 'ratio', '');
        if ($aspectRatio && $aspectRatio !== 'original') {
            $settings['aspectRatio'] = Sanitizer::sanitizeTextField($aspectRatio);
        } elseif (in_array($blockName, ['presto-player/youtube', 'presto-player/vimeo'], true)) {
            $settings['aspectRatio'] = '16:9';
        }

        $preload = Arr::get($attrs, 'preload', '');
        if ($preload) {
            $settings['preload'] = Sanitizer::sanitizeKey($preload);
        }

        if (Arr::get($attrs, 'playsInline') !== null) {
            $settings['playsInline'] = (bool) Arr::get($attrs, 'playsInline', true);
        }

        if (Arr::get($attrs, 'autoplay')) {
            $settings['autoplay'] = true;
        }

        $mutedPreview = Arr::get($attrs, 'mutedPreview', []);
        if (!empty(Arr::get($mutedPreview, 'enabled'))) {
            $settings['mutedAutoplay'] = true;
        }

        // PP 4.x per-video colour override. #00b3ff is PP's system default,
        // not an author choice — let global branding apply in that case.
        $color = Sanitizer::sanitizeTextField(Arr::get($attrs, 'color', ''));
        if ($color && strtolower($color) !== '#00b3ff') {
            $sanitized = sanitize_hex_color($color);
            if ($sanitized) {
                $settings['useCustomBranding'] = true;
                $settings['brandingColor']     = $sanitized;
            }
        }

        if (in_array($blockName, ['presto-player/youtube', 'presto-player/vimeo'], true)) {
            $videoId = Sanitizer::sanitizeTextField(Arr::get($attrs, 'video_id', ''));
            if ($videoId) {
                if (empty($settings['src'])) {
                    $settings['src'] = $blockName === 'presto-player/youtube'
                        ? 'https://www.youtube.com/watch?v=' . $videoId
                        : 'https://vimeo.com/' . $videoId;
                }

                if ($blockName === 'presto-player/youtube' && empty($settings['posterSrc'])) {
                    $settings['posterSrc'] = 'https://i.ytimg.com/vi/' . $videoId . '/maxresdefault.jpg';
                }
            }
        }

        $tracks = Arr::get($attrs, 'tracks', []);
        if (!empty($tracks)) {
            $settings['subtitles'] = self::mapTracks($tracks);
        }

        if ($providerInfo['provider'] === 'bunny') {
            $thumbnail = Sanitizer::escUrlRaw(Arr::get($attrs, 'thumbnail', ''));
            if ($thumbnail) {
                $settings['bunny'] = ['thumbnail' => $thumbnail];
            }
        }

        return $settings;
    }

    /**
     * Get video/audio duration from WordPress attachment metadata
     *
     * @param int $attachmentId
     * @return float Duration in seconds, 0 if unavailable
     */
    public static function getDurationFromAttachment($attachmentId)
    {
        $metadata = wp_get_attachment_metadata($attachmentId);
        if (!$metadata) {
            return 0;
        }

        if (!empty($metadata['length'])) {
            return (float) $metadata['length'];
        }

        if (!empty($metadata['length_formatted'])) {
            return (float) self::timeToSeconds($metadata['length_formatted']);
        }

        if (!empty($metadata['duration'])) {
            return (float) $metadata['duration'];
        }

        return 0;
    }

    /**
     * Map PP global settings to FP settings structure
     *
     * @param array $ppOptions Associative array of PP option values
     * @return array FP settings structure (partial, for merging)
     */
    public static function mapGlobalSettings($ppOptions)
    {
        $settings = [];

        $branding = Arr::get($ppOptions, 'presto_player_branding', []);
        if (!empty($branding)) {
            $settings['branding'] = array_filter([
                'logo_url'    => Sanitizer::escUrlRaw(Arr::get($branding, 'logo', '')),
                'logo_width'  => absint(Arr::get($branding, 'logo_width', 0)) ?: null,
                'brand_color' => sanitize_hex_color(Arr::get($branding, 'color', '')) ?: null,
            ]);

            $playerCss = Arr::get($branding, 'player_css', '');
            if ($playerCss) {
                $settings['general'] = [
                    'custom_css' => wp_strip_all_tags($playerCss),
                ];
            }
        }

        $googleAnalytics = Arr::get($ppOptions, 'presto_player_google_analytics', []);
        if (!empty($googleAnalytics)) {
            $settings['google_analytics'] = [
                'enabled'          => !empty(Arr::get($googleAnalytics, 'enable')),
                'measurement_id'   => Sanitizer::sanitizeTextField(Arr::get($googleAnalytics, 'measurement_id', '')),
                'use_existing_tag' => !empty(Arr::get($googleAnalytics, 'use_existing_tag')),
            ];
        }

        // PP nocookie maps to FP youtube.privacy_mode. Only emit on true so FP's
        // default isn't overridden by a PP install that never opted in.
        $youtube = Arr::get($ppOptions, 'presto_player_youtube', []);
        if (!empty(Arr::get($youtube, 'nocookie'))) {
            $settings['youtube'] = ['privacy_mode' => true];
        }

        return $settings;
    }

    /**
     * Map PP overlays to FP overlay format
     *
     * @param array $ppOverlays
     * @return array
     */
    private static function mapOverlays($ppOverlays)
    {
        if (empty($ppOverlays) || !is_array($ppOverlays)) {
            return [];
        }

        $overlays = [];
        foreach ($ppOverlays as $overlay) {
            $startSeconds = self::timeToSeconds(Arr::get($overlay, 'startTime', '0'));
            $endSeconds = self::timeToSeconds(Arr::get($overlay, 'endTime', '0'));
            $duration = max(1, $endSeconds - $startSeconds);

            $mapped = [
                'id'              => 'ol_' . wp_generate_uuid4(),
                'text'            => Sanitizer::sanitizeTextField(Arr::get($overlay, 'text', '')),
                'displayTime'     => $startSeconds,
                'duration'        => $duration,
                'position'        => 'position-' . Sanitizer::sanitizeKey(Arr::get($overlay, 'position', 'top-left')),
                'backgroundColor' => Sanitizer::sanitizeTextField(Arr::get($overlay, 'backgroundColor', '#0E121B')),
                'textColor'       => Sanitizer::sanitizeTextField(Arr::get($overlay, 'color', '#FFFFFF')),
            ];

            $link = Arr::get($overlay, 'link', []);
            if (!empty(Arr::get($link, 'url'))) {
                $mapped['link'] = Sanitizer::escUrlRaw(Arr::get($link, 'url', ''));
            }

            $overlays[] = $mapped;
        }

        return $overlays;
    }

    /**
     * Map PP chapters to FP format
     *
     * @param array $ppChapters
     * @return array
     */
    private static function mapChapters($ppChapters, $duration = 0)
    {
        if (empty($ppChapters) || !is_array($ppChapters)) {
            return [];
        }

        $sorted = array_values($ppChapters);
        usort($sorted, function ($a, $b) {
            return self::timeToSeconds(Arr::get($a, 'time', '0')) - self::timeToSeconds(Arr::get($b, 'time', '0'));
        });

        $chapters = [];
        $count = count($sorted);
        $lastEndTime = $duration ?: 3600;

        for ($i = 0; $i < $count; $i++) {
            $startTime = self::timeToSeconds(Arr::get($sorted[$i], 'time', '0'));
            $endTime = ($i + 1 < $count)
                ? self::timeToSeconds(Arr::get($sorted[$i + 1], 'time', '0'))
                : $lastEndTime;

            if ($i === 0 && $startTime > 0) {
                $startTime = 0;
            }

            $chapters[] = [
                'id'        => 'ch_' . wp_generate_uuid4(),
                'title'     => Sanitizer::sanitizeTextField(Arr::get($sorted[$i], 'title', '')),
                'startTime' => $startTime,
                'endTime'   => $endTime,
                'image'     => '',
            ];
        }

        return $chapters;
    }

    /**
     * Map PP tracks to FP subtitles format
     *
     * @param array $tracks
     * @return array
     */
    private static function mapTracks($tracks)
    {
        if (empty($tracks) || !is_array($tracks)) {
            return [];
        }

        $subtitles = [];
        $defaultSubtitleId = null;

        foreach ($tracks as $track) {
            $url = Sanitizer::escUrlRaw(Arr::get($track, 'src', Arr::get($track, 'url', '')));
            if (empty($url)) {
                continue;
            }

            $subtitle = [
                'id'       => 'sub_' . wp_generate_uuid4(),
                'label'    => Sanitizer::sanitizeTextField(Arr::get($track, 'label', '')),
                'url'      => $url,
                'language' => Sanitizer::sanitizeKey(
                    Arr::get($track, 'srcLang', Arr::get($track, 'srclang', 'en'))
                ),
            ];

            if ($defaultSubtitleId === null || !empty(Arr::get($track, 'default')) || !empty(Arr::get($track, 'is_default'))) {
                $defaultSubtitleId = $subtitle['id'];
            }

            $subtitles[] = $subtitle;
        }

        if ($defaultSubtitleId) {
            $subtitles = array_map(function ($subtitle) use ($defaultSubtitleId) {
                if ($subtitle['id'] === $defaultSubtitleId) {
                    $subtitle['is_default'] = true;
                }

                return $subtitle;
            }, $subtitles);
        }

        return $subtitles;
    }

    /**
     * Convert time string (MM:SS or HH:MM:SS) to seconds
     *
     * @param string|int $time
     * @return int
     */
    private static function timeToSeconds($time)
    {
        if (is_numeric($time)) {
            return (int) $time;
        }

        $parts = array_reverse(explode(':', (string) $time));
        $seconds = 0;

        foreach ($parts as $i => $part) {
            $seconds += (int) $part * pow(60, $i);
        }

        return $seconds;
    }

    /**
     * Decode a JSON LONGTEXT field from PP preset
     *
     * @param object $ppPreset
     * @param string $field
     * @return array
     */
    public static function decodeJsonField($ppPreset, $field)
    {
        if (!isset($ppPreset->$field)) {
            return [];
        }

        $value = $ppPreset->$field;

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_serialized($value)) {
            $unserialized = Helper::safeUnserialize($value);
            if (is_array($unserialized)) {
                return $unserialized;
            }
        }

        return [];
    }
}
