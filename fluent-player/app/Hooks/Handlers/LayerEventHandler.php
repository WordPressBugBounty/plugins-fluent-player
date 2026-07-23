<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Integrations\FluentCrm\BehaviorRegistry;

class LayerEventHandler extends AbstractBehaviorHandler
{
    const AJAX_ACTION = 'fluent_player_layer_event';
    const EVENTS = ['seen', 'completed', 'skipped'];

    protected function eventName()
    {
        return BehaviorRegistry::TRIGGER_LAYER;
    }

    protected function armedFor($mediaId)
    {
        return BehaviorRegistry::layersFor($mediaId);
    }

    protected function evaluate($mediaId, array $armed, array $state, array $identity)
    {
        $claims = json_decode((string) wp_unslash($this->app->request->get('crossed', '[]')), true);
        $claims = is_array($claims) ? array_slice($claims, 0, 50) : [];

        $mediaLayers = $this->indexMediaLayers($mediaId);
        $layersState = (array) Arr::get($state, 'layers', []);
        $firedSet = (array) Arr::get($state, 'layers_fired', []);
        // anonymous pings record the anchor but never emit/mark fired, so events catch up on identify
        $willEmit = !empty($identity['contact']) || (int) Arr::get($identity, 'user_id', 0) > 0;
        $now = time();
        $fired = [];

        foreach ($claims as $claim) {
            $parts = explode(':', (string) $claim);
            $event = array_pop($parts);
            $layerId = implode(':', $parts);

            if ($layerId === '' || !in_array($event, self::EVENTS, true)) {
                continue;
            }

            $armedEvents = Arr::get($armed, $layerId);
            $layer = Arr::get($mediaLayers, $layerId);
            if (!$armedEvents || !$layer) {
                continue;
            }

            if ($event !== 'seen' && !$this->isPlausible($event, $layer, $layersState, $layerId, $now)) {
                continue;
            }

            if (!Arr::has($layersState, $layerId . '.' . $event)) {
                $layersState[$layerId][$event] = $now;
            }

            $identityKey = $layerId . ':' . $event;
            if (in_array($identityKey, $firedSet, true)) {
                continue;
            }

            if ($willEmit && in_array($event, $armedEvents, true)) {
                $firedSet[] = $identityKey;
                $fired[] = [
                    'media_id'      => $mediaId,
                    'layer_id'      => $layerId,
                    'layer_type'    => \FluentPlayer\App\Integrations\FluentCrm\BehaviorRegistry::normalizedLayerType($layer),
                    'layer_title'   => (string) Arr::get($layer, 'title', ''),
                    'event'         => $event,
                    'user_id'       => $identity['user_id'],
                    'subscriber_id' => $identity['contact'] ? (int) $identity['contact']->id : 0,
                    'email'         => $identity['email'],
                ];
            }
        }

        $reached = [];
        foreach ($layersState as $layerId => $events) {
            foreach (array_keys((array) $events) as $event) {
                $reached[] = $layerId . ':' . $event;
            }
        }

        $state['layers'] = $layersState;
        $state['layers_fired'] = array_values(array_unique($firedSet));

        return [
            'state'   => $state,
            'fired'   => $fired,
            'reached' => $reached,
            'extra'   => [],
        ];
    }

    /**
     * completed/skipped require the layer to have been seen first; ad completion
     * additionally can't arrive faster than the skip offset allows.
     */
    protected function isPlausible($event, array $layer, array $layersState, $layerId, $now)
    {
        $seenAt = (int) Arr::get($layersState, $layerId . '.seen', 0);
        if (!$seenAt) {
            return false;
        }

        if ($event === 'completed' && Arr::get($layer, 'type') === 'ad') {
            $floor = max(1, (int) Arr::get($layer, 'skip_offset', 0));
            return ($now - $seenAt) >= min($floor, 30);
        }

        return true;
    }

    protected function indexMediaLayers($mediaId)
    {
        $settings = Media::getMediaSettings($mediaId);
        $layers = Arr::get($settings, 'layers', []);
        if (!is_array($layers)) {
            return [];
        }

        $indexed = [];
        foreach ($layers as $layer) {
            if (!is_array($layer) || empty($layer['id']) || Arr::get($layer, 'type') === 'hotspot') {
                continue;
            }
            // keep cta_type (email-capture detection) and title (timeline label)
            $indexed[(string) $layer['id']] = [
                'type'        => (string) Arr::get($layer, 'type', ''),
                'cta_type'    => (string) Arr::get($layer, 'cta_type', ''),
                'title'       => (string) Arr::get($layer, 'title', ''),
                'skip_offset' => (int) Arr::get($layer, 'skip_offset', 0),
            ];
        }
        return $indexed;
    }
}
