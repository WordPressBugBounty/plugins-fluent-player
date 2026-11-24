<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

class ClipService
{
    /**
     * @param array $settings
     * @return array<int, array{id:string,start:float,end:float,enabled:bool}>
     */
    public static function readClips($settings)
    {
        $clips = Arr::get($settings, 'clips');
        if (!is_array($clips) || empty($clips)) {
            return [];
        }

        $out = [];
        foreach (array_values($clips) as $index => $clip) {
            $clip = is_array($clip) ? $clip : [];
            $out[] = [
                'id'      => (isset($clip['id']) && is_string($clip['id']) && $clip['id'] !== '') ? $clip['id'] : 'clip_' . ($index + 1),
                'start'   => max(0.0, (float) Arr::get($clip, 'start', 0)),
                'end'     => max(0.0, (float) Arr::get($clip, 'end', 0)),
                'enabled' => Arr::get($clip, 'enabled', true) !== false,
            ];
        }
        return $out;
    }

    public static function clipsEnabled($settings)
    {
        return filter_var(Arr::get($settings, 'clips_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function activeClips($settings)
    {
        if (!self::clipsEnabled($settings)) {
            return [];
        }
        return array_values(array_filter(
            self::readClips($settings),
            function ($clip) {
                return !empty($clip['enabled']);
            }
        ));
    }

    /**
     * The clip a media plays when a placement makes no choice: its own editor pick, else its only clip.
     *
     * @return array{enabled:bool,start:float,end:float}|null
     */
    public static function defaultClip($mediaId, $settings)
    {
        if (!self::activeClips($settings)) {
            return null;
        }
        $post = get_post($mediaId);
        $block = $post ? MediaRenderer::findMediaBlock($post, $mediaId) : null;
        return self::effectiveClip($settings, $block ? Arr::get($block, 'attrs.selectedClipId') : null);
    }

    /**
     * '__full__' forces the full video; an unknown id falls back to the single-clip rule.
     *
     * @return array{enabled:bool,start:float,end:float}|null
     */
    public static function effectiveClip($settings, $selectedId = null)
    {
        if ($selectedId === '__full__') {
            return null;
        }
        $active = self::activeClips($settings);

        $clip = null;
        if ($selectedId) {
            foreach ($active as $candidate) {
                if ($candidate['id'] === $selectedId) {
                    $clip = $candidate;
                    break;
                }
            }
        }
        if (!$clip && count($active) === 1) {
            $clip = $active[0];
        }

        if (!$clip) {
            return null;
        }

        return ['enabled' => true, 'start' => $clip['start'], 'end' => $clip['end']];
    }
}
