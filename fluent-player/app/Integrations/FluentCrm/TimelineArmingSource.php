<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\Behavior\TimelineFields;
use FluentPlayer\App\Services\EmailProviderService;
use FluentPlayer\Framework\Support\Arr;

/**
 * Arms whatever the FluentCRM "contact timeline" settings track, so timeline
 * logging works without any automation targeting the media. The bridge uses
 * matchesMilestone()/matchesLayer() to consume only its own arming.
 */
class TimelineArmingSource
{
    protected static $settingsCache = null;

    public static function settings()
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }
        // frontend requests never construct the REST controller — without
        // registered providers getProvidersSettings drops the fluentcrm key
        if (!did_action('fluent_player/register_email_providers')) {
            EmailProviderService::init();
            EmailProviderService::clearCache();
        }
        $all = EmailProviderService::getProvidersSettings();
        return self::$settingsCache = (array) Arr::get($all, 'fluentcrm', []);
    }

    public static function resetSettingsCache()
    {
        self::$settingsCache = null;
    }

    public static function collect(&$result)
    {
        try {
            $config = self::activeConfig();
            if (!$config) {
                return;
            }

            $result['active'] = true;

            if ($config['scope'] === 'all') {
                $entry = &BehaviorRegistry::ensureEntry($result, 'any');
                self::applyMilestones($entry, $config);

                if ($config['layer_events']) {
                    if ($config['layer_type_scope'] === 'types' && $config['layer_types']) {
                        foreach ($config['layer_types'] as $type) {
                            $entry['layer_types'][$type] = array_values(array_unique(array_merge(
                                (array) Arr::get($entry, 'layer_types.' . $type, []),
                                $config['layer_events']
                            )));
                        }
                    } elseif ($config['layer_type_scope'] === 'all') {
                        $entry['layers_all'] = array_values(array_unique(array_merge(
                            (array) Arr::get($entry, 'layers_all', []),
                            $config['layer_events']
                        )));
                    }
                }
                unset($entry);
                return;
            }

            foreach ($config['media_ids'] as $mediaId) {
                $entry = &BehaviorRegistry::ensureEntry($result, $mediaId);
                self::applyMilestones($entry, $config);

                if ($config['layer_events']) {
                    foreach (self::armedLayerIdsFor($mediaId, $config) as $layerId) {
                        $entry['layers'][$layerId] = array_values(array_unique(array_merge(
                            (array) Arr::get($entry, 'layers.' . $layerId, []),
                            $config['layer_events']
                        )));
                    }
                }
                unset($entry);
            }
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * Bridge gate: did THIS fired milestone match the timeline settings
     * (and not just some automation's arming)?
     */
    public static function matchesMilestone(array $ctx)
    {
        $config = self::activeConfig();
        if (!$config || !self::mediaInScope((int) Arr::get($ctx, 'media_id'), $config)) {
            return false;
        }

        $kind = (string) Arr::get($ctx, 'milestone', '');
        if (!in_array($kind, $config['milestones'], true)) {
            return false;
        }

        if ($kind === 'completed') {
            $boundary = Arr::get($ctx, 'boundary');
            // null boundary = genuine ended, which satisfies any threshold
            if ($boundary !== null && abs((float) $boundary - (float) $config['threshold']) > 0.0001) {
                return false;
            }
        }

        return true;
    }

    public static function matchesLayer(array $ctx)
    {
        $config = self::activeConfig();
        if (!$config || !$config['layer_events']) {
            return false;
        }

        $mediaId = (int) Arr::get($ctx, 'media_id');
        if (!self::mediaInScope($mediaId, $config)) {
            return false;
        }

        if (!in_array((string) Arr::get($ctx, 'event', ''), $config['layer_events'], true)) {
            return false;
        }

        if ($config['scope'] === 'all') {
            if ($config['layer_type_scope'] === 'types') {
                return in_array((string) Arr::get($ctx, 'layer_type', ''), $config['layer_types'], true);
            }
            return $config['layer_type_scope'] === 'all';
        }

        if ($config['layer_scope'] === 'specific') {
            $needle = $mediaId . ':' . (string) Arr::get($ctx, 'layer_id', '');
            return in_array($needle, $config['layer_ids'], true);
        }

        return true;
    }

    protected static function mediaInScope($mediaId, array $config)
    {
        if ($config['scope'] === 'all') {
            return true;
        }
        return in_array($mediaId, $config['media_ids'], true);
    }

    protected static function activeConfig()
    {
        $s = self::settings();
        if (!Arr::isTrue($s, 'timeline_enabled')) {
            return null;
        }

        $milestones = array_values(array_intersect(
            array_map('strval', (array) Arr::get($s, 'timeline_milestones', [])),
            TimelineFields::MILESTONES
        ));
        $layerEvents = array_values(array_intersect(
            array_map('strval', (array) Arr::get($s, 'timeline_layer_events', [])),
            TimelineFields::LAYER_EVENTS
        ));

        if (!$milestones && !$layerEvents) {
            return null;
        }

        $scope = Arr::get($s, 'timeline_scope') === 'all' ? 'all' : 'selected';
        $mediaIds = array_values(array_filter(array_map('absint', (array) Arr::get($s, 'timeline_media_ids', []))));

        if ($scope === 'selected' && !$mediaIds) {
            return null;
        }

        $threshold = (float) Arr::get($s, 'timeline_completion_threshold', 0.9);
        if ($threshold <= 0 || $threshold > 1) {
            $threshold = 0.9;
        }

        return [
            'scope'            => $scope,
            'media_ids'        => $mediaIds,
            'milestones'       => $milestones,
            'threshold'        => $threshold,
            'layer_events'     => $layerEvents,
            'layer_scope'      => Arr::get($s, 'timeline_layer_scope') === 'specific' ? 'specific' : 'all',
            'layer_type_scope' => Arr::get($s, 'timeline_layer_type_scope') === 'types' ? 'types' : 'all',
            'layer_types'      => array_values(array_intersect(
                array_map('strval', (array) Arr::get($s, 'timeline_layer_types', [])),
                TimelineFields::layerTypeValues()
            )),
            'layer_ids'        => array_values(array_map('strval', (array) Arr::get($s, 'timeline_layer_ids', []))),
        ];
    }

    protected static function applyMilestones(array &$entry, array $config)
    {
        foreach ($config['milestones'] as $milestone) {
            BehaviorRegistry::applyMilestone($entry['milestones'], $milestone, $config['threshold']);
        }
    }

    protected static function armedLayerIdsFor($mediaId, array $config)
    {
        if ($config['layer_scope'] === 'specific') {
            $ids = [];
            foreach ($config['layer_ids'] as $encoded) {
                $parts = explode(':', $encoded, 2);
                if ((int) Arr::get($parts, 0) === (int) $mediaId && Arr::get($parts, 1, '') !== '') {
                    $ids[] = (string) $parts[1];
                }
            }
            return $ids;
        }

        $ids = [];
        foreach (BehaviorRegistry::mediaLayers($mediaId) as $layer) {
            $layerId = (string) Arr::get((array) $layer, 'id', '');
            if ($layerId !== '') {
                $ids[] = $layerId;
            }
        }
        return $ids;
    }
}
