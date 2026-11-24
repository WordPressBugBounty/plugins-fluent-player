<?php

namespace FluentPlayer\App\Modules\MCP\Tools;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Modules\MCP\Support\ErrorCodes;
use FluentPlayer\App\Modules\MCP\Support\MCPHelper;
use FluentPlayer\App\Modules\MCP\Support\Mutation;
use FluentPlayer\App\Modules\MCP\Support\WriteGuard;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\App\Services\PresetService;

class PresetTools
{
    const MAX_MEDIA_PER_CALL = 50;

    public static function definitions()
    {
        $authoring = Helper::authoringCapability();
        $group = __('Presets', 'fluent-player');

        return [
            'fluent-player/list-presets' => [
                'label'            => __('List Presets', 'fluent-player'),
                'description'      => __('Player presets (control layouts and styles) that can be applied to media. Pass preset_slug to get that one preset\'s full settings: skin, controls, behaviours, caption style, context menu (email provider settings are not included).', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => ['preset_slug' => ['type' => 'string']],
                ],
                'execute_callback' => [self::class, 'listPresets'],
            ],
            'fluent-player/apply-preset' => [
                'label'            => __('Apply Preset', 'fluent-player'),
                'description'      => __('Switch up to 50 media items to one preset.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['preset_slug', 'media_ids'],
                    'properties' => array_merge([
                        'preset_slug' => ['type' => 'string'],
                        'media_ids'   => [
                            'type'     => 'array',
                            'items'    => ['type' => 'integer', 'minimum' => 1],
                            'minItems' => 1,
                            'maxItems' => self::MAX_MEDIA_PER_CALL,
                        ],
                    ], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'applyPreset'],
            ],
        ];
    }

    public static function listPresets($params = [])
    {
        if (!empty($params['preset_slug'])) {
            return self::getPreset($params);
        }

        $presets = [];
        foreach ((array) PresetService::all() as $preset) {
            $presets[] = [
                'slug'        => isset($preset['slug']) ? $preset['slug'] : '',
                'title'       => MCPHelper::untrusted(isset($preset['name']) ? $preset['name'] : (isset($preset['title']) ? $preset['title'] : '')),
                'is_default'  => isset($preset['slug']) && $preset['slug'] === PresetService::getDefaultSlug(),
            ];
        }

        /* translators: %d: number of presets */
        return MCPHelper::envelope(sprintf(_n('%d preset.', '%d presets.', count($presets), 'fluent-player'), count($presets)), $presets, ['content_warning' => MCPHelper::CONTENT_WARNING]);
    }

    private static function getPreset($params)
    {
        $preset = PresetService::resolveRef((string) $params['preset_slug']);
        if (!$preset) {
            return MCPHelper::error(ErrorCodes::NOT_FOUND, __('Preset not found.', 'fluent-player'), ['preset_slug' => $params['preset_slug']]);
        }

        $preset['settings'] = MediaService::stripSensitiveProviderConfig(isset($preset['settings']) ? (array) $preset['settings'] : []);
        foreach (['name', 'title'] as $key) {
            if (isset($preset[$key])) {
                $preset[$key] = MCPHelper::untrusted($preset[$key]);
            }
        }
        $summary = isset($preset['name']) ? $preset['name'] : (isset($preset['title']) ? $preset['title'] : $preset['slug']);

        return MCPHelper::envelope($summary, $preset, ['content_warning' => MCPHelper::CONTENT_WARNING]);
    }

    /**
     * Free swaps the ambient preset for Minimal when it renders, so applying it would only look like it worked.
     *
     * @return \WP_Error|null
     */
    public static function refuseProPreset(array $preset, $field)
    {
        if (Helper::hasPro() || !PresetService::isAmbientPreset($preset)) {
            return null;
        }

        return MCPHelper::error(ErrorCodes::FORBIDDEN, __('The Ambient preset needs Fluent Player Pro.', 'fluent-player'), ['field' => $field]);
    }

    public static function applyPreset($params)
    {
        $preset = PresetService::resolveRef(isset($params['preset_slug']) ? (string) $params['preset_slug'] : '');
        if (!$preset) {
            return MCPHelper::error(ErrorCodes::NOT_FOUND, __('Preset not found.', 'fluent-player'), ['preset_slug' => isset($params['preset_slug']) ? $params['preset_slug'] : '']);
        }
        if ($proPreset = self::refuseProPreset($preset, 'preset_slug')) {
            return $proPreset;
        }
        $slug = $preset['slug'];

        $ids = array_values(array_unique(array_map('absint', (array) $params['media_ids'])));
        if (count($ids) > self::MAX_MEDIA_PER_CALL) {
            return MCPHelper::error(ErrorCodes::LIMIT_EXCEEDED, __('At most 50 media items per call.', 'fluent-player'));
        }
        sort($ids);

        $medias = [];
        foreach ($ids as $id) {
            $media = MediaTools::findEditableMedia(['media_id' => $id]);
            if (is_wp_error($media)) {
                return $media;
            }
            $medias[$id] = $media;
        }

        $fingerprint = $slug;
        foreach ($medias as $media) {
            $fingerprint .= '|' . MediaTools::fingerprint($media);
        }

        return Mutation::runGuarded(
            'fluent-player/apply-preset',
            $params,
            'preset:' . $slug . ':' . md5(implode(',', $ids)),
            md5($fingerprint),
            function () use ($medias, $slug) {
                $rows = [];
                foreach ($medias as $media) {
                    $rows[] = [
                        'id'   => (int) $media->ID,
                        'title' => MCPHelper::untrusted($media->post_title),
                        'from' => isset($media->settings['preset_slug']) ? $media->settings['preset_slug'] : '',
                        'to'   => $slug,
                    ];
                }

                return ['media' => $rows, 'content_warning' => MCPHelper::CONTENT_WARNING];
            },
            function () use ($medias, $slug, $preset) {
                $updated = [];
                $failed = [];
                foreach (array_keys($medias) as $id) {
                    // Each item takes its own lease and is re-read, so a concurrent single-media write is not overwritten.
                    $receipt = WriteGuard::claim('fluent-player/apply-preset', 'media:' . $id);
                    if (!$receipt) {
                        $failed[] = ['id' => $id, 'error' => __('Another change to this media is in progress.', 'fluent-player')];
                        continue;
                    }
                    try {
                        wp_cache_delete($id, 'post_meta');
                        $settings = MediaTools::applyPresetBehaviors((array) get_post_meta($id, 'settings', true), $preset);
                        $result = MediaTools::saveSettings($id, $settings);
                    } finally {
                        WriteGuard::release('fluent-player/apply-preset', 'media:' . $id, $receipt);
                    }
                    if (is_wp_error($result)) {
                        $failed[] = ['id' => $id, 'error' => $result->get_error_message()];
                    } else {
                        $updated[] = $id;
                    }
                }

                return MCPHelper::envelope(
                    /* translators: 1: updated count, 2: failed count */
                    sprintf(__('Applied preset to %1$d media, %2$d failed.', 'fluent-player'), count($updated), count($failed)),
                    ['preset_slug' => $slug, 'updated' => $updated, 'failed' => $failed],
                    MediaTools::editorNote()
                );
            },
            'preset:' . $slug
        );
    }
}
