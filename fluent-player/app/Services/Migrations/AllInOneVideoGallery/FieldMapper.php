<?php

namespace FluentPlayer\App\Services\Migrations\AllInOneVideoGallery;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

/**
 * Expresses a resolved AIOVG source in FluentPlayer's settings vocabulary.
 *
 * SourceResolver has already decided WHICH of the row's competing sources wins;
 * this only translates that decision. No database access, no precedence logic —
 * a pure transformer, matching PrestoPlayer\FieldMapper.
 */
class FieldMapper
{
    /** Providers with no intrinsic dimensions before load — without a ratio the embed collapses. */
    const RATIO_REQUIRED_PROVIDERS = ['youtube', 'vimeo'];

    const DEFAULT_ASPECT_RATIO = '16:9';

    /**
     * @param array  $resolved   SourceResolver::resolve() output.
     * @param string $presetSlug FluentPlayer preset to attach.
     * @return array Empty when the source cannot be migrated.
     */
    public static function mapMediaSettings(array $resolved, $presetSlug = 'default')
    {
        if (empty($resolved['migratable']) || empty($resolved['src'])) {
            return [];
        }

        $isAudio = Arr::get($resolved, 'mediaType') === 'audio';
        $provider = (string) Arr::get($resolved, 'provider', 'external');

        $settings = [
            'viewType'               => $isAudio ? 'audio' : 'video',
            'provider'               => $provider,
            // escUrlRaw, not esc_url: an escaped URL turns & into &#038; and breaks
            // signed CDN query strings.
            'src'                    => Sanitizer::escUrlRaw(Arr::get($resolved, 'src', '')),
            'posterSrc'              => Sanitizer::escUrlRaw(Arr::get($resolved, 'poster', '')),
            'preset_slug'            => $presetSlug ?: 'default',
            'mediaType'              => $isAudio ? 'audio' : 'video',
            'streamType'             => 'on-demand',
            'loadStrategy'           => 'visible',
            'language_mappings'      => [],
            'show_language_switcher' => false,
        ];

        $attachmentId = (int) Arr::get($resolved, 'attachment_id', 0);
        if ($attachmentId > 0) {
            $settings['attachment_id'] = $attachmentId;
        }

        // Only set a duration we actually know. Writing 0 makes the player display
        // 0:00 as the total instead of reading it off the media element.
        $duration = (float) Arr::get($resolved, 'duration', 0);
        if ($duration > 0) {
            $settings['duration'] = $duration;
        }

        if (in_array($provider, self::RATIO_REQUIRED_PROVIDERS, true)) {
            $settings['aspectRatio'] = self::DEFAULT_ASPECT_RATIO;
        }

        $bunnyVideoId = (string) Arr::get($resolved, 'bunny_video_id', '');
        if ($provider === 'bunny' && $bunnyVideoId !== '') {
            // AIOVG stores only the video id; the library id lives in its global
            // Bunny settings rather than per video, so it is left for the user to
            // fill in if their setup needs it.
            $settings['bunny'] = [
                'video_id' => Sanitizer::sanitizeTextField($bunnyVideoId),
            ];
        }

        return $settings;
    }
}
