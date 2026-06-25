<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Services\PresetService;

class PresetMigrator
{
    /**
     * Migrate Presto Player presets to Fluent Player
     *
     * @param array $presetIds PP preset IDs to migrate (both video and audio)
     * @return array Migration results
     */
    public static function migrate($presetIds = [], $force = false)
    {
        $allPresets = Scanner::scanPresets();
        $map = get_option(Scanner::MAP_OPTION, []);

        if (!isset($map['presets'])) {
            $map['presets'] = [];
        }

        $migrated = 0;
        $skipped = 0;
        $errors = [];
        $externalProviders = [];

        foreach ($allPresets as $preset) {
            $ppId = $preset['id'];
            $mapKey = $preset['is_audio'] ? 'audio_' . $ppId : 'video_' . $ppId;

            if (!empty($presetIds) && !in_array($mapKey, $presetIds) && !in_array($ppId, $presetIds)) {
                continue;
            }

            $existingSlug = !empty($map['presets'][$mapKey]) ? $map['presets'][$mapKey] : null;

            if ($existingSlug && !$force) {
                $skipped++;
                continue;
            }

            try {
                $fpPreset = PresetMapper::map($preset['raw'], $preset['is_audio']);

                if ($existingSlug) {
                    $fpPreset['slug'] = $existingSlug;
                } else {
                    $fpPreset['slug'] = self::ensureUniqueSlug($fpPreset['slug']);
                }
                $fpPreset['settings']['slug'] = $fpPreset['slug'];

                PresetService::save($fpPreset['slug'], $fpPreset);

                $map['presets'][$mapKey] = $fpPreset['slug'];
                $migrated++;

                foreach (Arr::get($fpPreset, 'settings.email_capture.providers', []) as $p) {
                    $pType = Arr::get($p, 'type', '');
                    if ($pType && $pType !== 'fluentcrm' && !in_array($pType, $externalProviders, true)) {
                        $externalProviders[] = $pType;
                    }
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'id'    => $ppId,
                    'name'  => $preset['name'],
                    'type'  => $preset['type'],
                    'error' => __('Failed to migrate this preset.', 'fluent-player'),
                ];
            }
        }

        update_option(Scanner::MAP_OPTION, $map, false);

        return [
            'migrated'           => $migrated,
            'skipped'            => $skipped,
            'errors'             => $errors,
            'external_providers' => $externalProviders,
            'message'            => sprintf(
                /* translators: 1: number of presets successfully migrated, 2: number of presets skipped. */
                __('%1$d presets migrated, %2$d skipped.', 'fluent-player'),
                $migrated,
                $skipped
            ),
        ];
    }

    /**
     * Ensure the preset slug is unique by appending a number if needed
     *
     * @param string $slug
     * @return string
     */
    private static function ensureUniqueSlug($slug)
    {
        $existing = PresetService::all();
        $originalSlug = $slug;
        $counter = 1;

        while (isset($existing[$slug])) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

}
