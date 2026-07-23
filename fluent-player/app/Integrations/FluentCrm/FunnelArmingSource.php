<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelSequence;
use FluentPlayer\Framework\Support\Arr;

/**
 * Arms whatever published automations target: milestone triggers/goals and
 * layer triggers/goals. Extracted verbatim from the pre-aggregator registry.
 */
class FunnelArmingSource
{
    public static function collect(&$result)
    {
        try {
            if (!class_exists(Funnel::class)) {
                return;
            }

            $triggerNames = [BehaviorRegistry::TRIGGER_MILESTONE, BehaviorRegistry::TRIGGER_LAYER];

            $funnels = Funnel::whereIn('trigger_name', $triggerNames)
                ->where('status', 'published')
                ->get();
            foreach ($funnels as $funnel) {
                $config = wp_parse_args((array) $funnel->settings, (array) $funnel->conditions);
                self::absorb($result, $funnel->trigger_name, $config);
            }

            if (class_exists(FunnelSequence::class)) {
                $publishedIds = Funnel::where('status', 'published')->get()->pluck('id')->toArray();
                if (!empty($publishedIds)) {
                    $sequences = FunnelSequence::whereIn('action_name', $triggerNames)
                        ->whereIn('funnel_id', $publishedIds)
                        ->get();
                    foreach ($sequences as $sequence) {
                        self::absorb($result, $sequence->action_name, (array) $sequence->settings);
                    }
                }
            }
        } catch (\Exception $e) {
            return;
        }
    }

    protected static function absorb(&$result, $triggerName, $config)
    {
        if ($triggerName === BehaviorRegistry::TRIGGER_LAYER) {
            self::absorbLayer($result, $config);
            return;
        }

        $extracted = self::extractMilestone($config);
        if ($extracted === null) {
            return;
        }
        $result['active'] = true;

        $targets = $extracted['wildcard'] ? ['any'] : $extracted['media_ids'];
        foreach ($targets as $target) {
            $entry = &BehaviorRegistry::ensureEntry($result, $target);
            BehaviorRegistry::applyMilestone($entry['milestones'], $extracted['milestone'], $extracted['threshold']);
            unset($entry);
        }
    }

    protected static function absorbLayer(&$result, $config)
    {
        $extracted = self::extractLayer($config);
        if ($extracted === null) {
            return;
        }
        $result['active'] = true;

        foreach ($extracted['media_layers'] as $mediaId => $layerIds) {
            $entry = &BehaviorRegistry::ensureEntry($result, $mediaId);
            foreach ($layerIds as $layerId) {
                $existing = Arr::get($entry, 'layers.' . $layerId, []);
                $entry['layers'][$layerId] = array_values(array_unique(array_merge($existing, [$extracted['event']])));
            }
            unset($entry);
        }
    }

    protected static function extractMilestone($config)
    {
        $config = is_array($config) ? $config : [];

        $milestone = (string) Arr::get($config, 'milestone', '');
        if (!in_array($milestone, ['started', 'q25', 'q50', 'q75', 'completed'], true)) {
            return null;
        }

        $target = self::extractMediaTargets($config);
        if ($target === null) {
            return null;
        }

        $threshold = (float) Arr::get($config, 'completion_threshold', 0.9);
        if ($threshold <= 0 || $threshold > 1) {
            $threshold = 0.9;
        }

        return $target + ['milestone' => $milestone, 'threshold' => $threshold];
    }

    protected static function extractLayer($config)
    {
        $config = is_array($config) ? $config : [];

        $event = (string) Arr::get($config, 'layer_event', '');
        if (!in_array($event, ['seen', 'completed', 'skipped'], true)) {
            return null;
        }

        $values = Arr::get($config, 'layer_ids', []);
        $values = is_array($values) ? $values : [];

        $mediaLayers = [];
        foreach ($values as $value) {
            $parts = explode(':', (string) $value, 2);
            $mediaId = (int) Arr::get($parts, 0, 0);
            $layerId = trim((string) Arr::get($parts, 1, ''));
            if ($mediaId > 0 && $layerId !== '') {
                $mediaLayers[$mediaId][] = $layerId;
            }
        }

        if (empty($mediaLayers)) {
            return null;
        }

        return ['event' => $event, 'media_layers' => $mediaLayers];
    }

    protected static function extractMediaTargets($config)
    {
        $mediaIds = Arr::get($config, 'media_ids', []);
        $mediaIds = is_array($mediaIds) ? $mediaIds : [];

        $wildcard = empty($mediaIds) || in_array('any', $mediaIds, true);
        $ids = [];
        if (!$wildcard) {
            foreach ($mediaIds as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            if (empty($ids)) {
                return null;
            }
        }

        return ['wildcard' => $wildcard, 'media_ids' => $ids];
    }
}
