<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\Framework\Support\Arr;

/**
 * Aggregates arming sources: each source declares which media/events matter
 * to its consumers (funnels, contact timeline, future destinations). The
 * engine downstream never cares WHY a milestone or layer is armed.
 */
class BehaviorRegistry
{
    const TRIGGER_MILESTONE = 'fluent_player/media_milestone';
    const TRIGGER_LAYER = 'fluent_player/layer_event';

    const CACHE_KEY = 'flp_behavior_registry_v1';
    // 120s: no FluentCRM action fires on condition edits, TTL is the only propagation there
    const CACHE_TTL = 120;

    protected static $runtime = null;

    protected static function sources()
    {
        return [FunnelArmingSource::class, TimelineArmingSource::class];
    }

    public static function flush()
    {
        delete_transient(self::CACHE_KEY);
        self::$runtime = null;
        TimelineArmingSource::resetSettingsCache();
    }

    public static function map()
    {
        if (self::$runtime !== null) {
            return self::$runtime;
        }

        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached) && Arr::has($cached, 'active')) {
            return self::$runtime = $cached;
        }

        $map = self::build();
        set_transient(self::CACHE_KEY, $map, self::CACHE_TTL);
        return self::$runtime = $map;
    }

    public static function isActive()
    {
        return Arr::isTrue(self::map(), 'active');
    }

    public static function armedFor($mediaId)
    {
        $milestones = Arr::get(self::entryFor($mediaId), 'milestones');
        if (!$milestones) {
            return null;
        }
        if (empty($milestones['started']) && empty($milestones['quartiles']) && empty($milestones['completions'])) {
            return null;
        }
        return $milestones;
    }

    public static function layersFor($mediaId)
    {
        return Arr::get(self::entryFor($mediaId), 'layers') ?: null;
    }

    protected static function entryFor($mediaId)
    {
        $map = self::map();
        if (!Arr::isTrue($map, 'active')) {
            return null;
        }

        $specific = Arr::get($map, 'media.' . (int) $mediaId);
        $wildcard = Arr::get($map, 'any');

        if ($specific === null && $wildcard === null) {
            return null;
        }

        $a = $specific !== null ? $specific : self::emptyEntry();
        $b = $wildcard !== null ? $wildcard : self::emptyEntry();

        $entry = [
            'milestones' => [
                'started'     => Arr::isTrue($a, 'milestones.started') || Arr::isTrue($b, 'milestones.started'),
                'quartiles'   => array_values(array_unique(array_merge($a['milestones']['quartiles'], $b['milestones']['quartiles']))),
                'completions' => array_values(array_unique(array_merge($a['milestones']['completions'], $b['milestones']['completions']))),
            ],
            'layers' => self::mergeLayers(Arr::get($a, 'layers', []), Arr::get($b, 'layers', [])),
        ];

        $entry['layers'] = self::mergeLayers(
            $entry['layers'],
            self::expandWildcardLayers($b, (int) $mediaId)
        );

        return $entry;
    }

    public static function clientConfig()
    {
        $map = self::map();
        if (!Arr::isTrue($map, 'active')) {
            return ['active' => false];
        }

        $media = [];
        foreach (Arr::get($map, 'media', []) as $mediaId => $entry) {
            $media[$mediaId] = self::clientEntry($entry);
        }

        $wildcard = Arr::get($map, 'any');

        return [
            'active' => true,
            'media'  => (object) $media,
            'any'    => $wildcard !== null ? self::clientEntry($wildcard) : null,
        ];
    }

    protected static function clientEntry(array $entry)
    {
        $out = $entry['milestones'];
        if (!empty($entry['layers'])) {
            $out['layers'] = $entry['layers'];
        }
        // the reporter can't enumerate other media's layers; it arms by
        // event list / type and the server re-verifies against concrete ids
        if (!empty($entry['layers_all'])) {
            $out['layers_all'] = array_values($entry['layers_all']);
        }
        if (!empty($entry['layer_types'])) {
            $out['layer_types'] = $entry['layer_types'];
        }
        return $out;
    }

    protected static function build()
    {
        $result = ['active' => false, 'any' => null, 'media' => []];

        try {
            foreach (self::sources() as $source) {
                $source::collect($result);
            }
        } catch (\Exception $e) {
            return ['active' => false, 'any' => null, 'media' => []];
        }

        $result['media'] = array_map([self::class, 'finalizeEntry'], $result['media']);
        if (is_array($result['any'])) {
            $result['any'] = self::finalizeEntry($result['any']);
        }

        return $result;
    }

    public static function emptyEntry()
    {
        return [
            'milestones' => ['started' => false, 'quartiles' => [], 'completions' => []],
            'layers'     => [],
        ];
    }

    /**
     * @return array reference to the entry for 'any' or a media id
     */
    public static function &ensureEntry(&$result, $target)
    {
        if ($target === 'any') {
            if ($result['any'] === null) {
                $result['any'] = self::emptyEntry();
            }
            return $result['any'];
        }

        if (!isset($result['media'][$target])) {
            $result['media'][$target] = self::emptyEntry();
        }
        return $result['media'][$target];
    }

    public static function applyMilestone(&$milestones, $milestone, $threshold)
    {
        if ($milestone === 'started') {
            $milestones['started'] = true;
        } elseif ($milestone === 'completed') {
            $milestones['completions'][number_format($threshold, 4, '.', '')] = $threshold;
        } else {
            $milestones['quartiles'][$milestone] = true;
        }
    }

    /**
     * Canonical behavior taxonomy type — email capture is stored as a cta subtype.
     */
    public static function normalizedLayerType($layer)
    {
        $type = (string) Arr::get((array) $layer, 'type', '');
        if ($type === 'cta' && Arr::get((array) $layer, 'cta_type') === 'email') {
            return 'email';
        }
        return $type;
    }

    /**
     * @return array raw layer definitions from the media settings
     */
    public static function mediaLayers($mediaId)
    {
        $layers = Arr::get(Media::getMediaSettings((int) $mediaId), 'layers', []);
        return is_array($layers) ? $layers : [];
    }

    /**
     * Resolves wildcard layer arming (all layers / by type under All-media
     * scope) to this media's concrete layer ids, keeping the evaluator and
     * client contract id-based.
     */
    protected static function expandWildcardLayers(array $wildcardEntry, $mediaId)
    {
        $allEvents = array_values((array) Arr::get($wildcardEntry, 'layers_all', []));
        $typeEvents = (array) Arr::get($wildcardEntry, 'layer_types', []);

        if (!$allEvents && !$typeEvents) {
            return [];
        }

        $expanded = [];
        foreach (self::mediaLayers($mediaId) as $layer) {
            $layerId = (string) Arr::get((array) $layer, 'id', '');
            if ($layerId === '') {
                continue;
            }
            $events = $allEvents;
            $type = self::normalizedLayerType($layer);
            if (isset($typeEvents[$type])) {
                $events = array_merge($events, (array) $typeEvents[$type]);
            }
            if ($events) {
                $expanded[$layerId] = array_values(array_unique($events));
            }
        }

        return $expanded;
    }

    protected static function finalizeEntry(array $entry)
    {
        $quartiles = array_values(array_intersect(['q25', 'q50', 'q75'], array_keys($entry['milestones']['quartiles'])));
        $completions = array_values($entry['milestones']['completions']);
        sort($completions, SORT_NUMERIC);

        $out = [
            'milestones' => [
                'started'     => !empty($entry['milestones']['started']),
                'quartiles'   => $quartiles,
                'completions' => $completions,
            ],
            'layers' => $entry['layers'],
        ];

        foreach (['layers_all', 'layer_types'] as $key) {
            if (!empty($entry[$key])) {
                $out[$key] = $entry[$key];
            }
        }

        return $out;
    }

    public static function mergeLayers(array $a, array $b)
    {
        $merged = $a;
        foreach ($b as $layerId => $events) {
            $merged[$layerId] = array_values(array_unique(array_merge(Arr::get($merged, $layerId, []), $events)));
        }
        return $merged;
    }
}
