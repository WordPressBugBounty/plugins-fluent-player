<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Integrations\FluentCrm\BehaviorRegistry;
use FluentPlayer\App\Integrations\FluentCrm\MilestoneEvaluator;

class MediaMilestoneHandler extends AbstractBehaviorHandler
{
    const AJAX_ACTION = 'fluent_player_media_milestone';

    protected function eventName()
    {
        return BehaviorRegistry::TRIGGER_MILESTONE;
    }

    protected function armedFor($mediaId)
    {
        return BehaviorRegistry::armedFor($mediaId);
    }

    protected function evaluate($mediaId, array $armed, array $state, array $identity)
    {
        $segments = json_decode((string) wp_unslash($this->app->request->get('segments', '[]')), true);
        if (!is_array($segments)) {
            $segments = [];
        }
        if (count($segments) > 5000) {
            $segments = array_slice($segments, 0, 5000);
        }

        $now = time();
        $stateTs = (int) Arr::get($state, 'ts', 0);
        $elapsed = $stateTs > 0 ? max(0, $now - $stateTs) : 0;

        $result = MilestoneEvaluator::evaluate([
            'segments'         => $segments,
            'storedSegments'   => Arr::get($state, 'union', []),
            'serverDuration'   => $this->resolveServerDuration($mediaId),
            'clientDuration'   => (float) $this->app->request->get('duration', 0),
            'clientStarted'    => true,
            'ended'            => filter_var($this->app->request->get('ended', false), FILTER_VALIDATE_BOOLEAN),
            'armed'            => $armed,
            'firedIdentities'  => Arr::get($state, 'fired', []),
            'prevWatched'      => Arr::get($state, 'watched', 0.0),
            'elapsedSinceLast' => $elapsed,
        ]);

        $newFired = Arr::get($state, 'fired', []);
        foreach ($result['fired'] as $m) {
            $newFired[] = $m['id'];
        }
        $newFired = array_values(array_unique($newFired));

        $ctxs = [];
        foreach ($result['fired'] as $m) {
            $ctxs[] = [
                'media_id'      => $mediaId,
                'milestone'     => $m['key'],
                'boundary'      => $m['boundary'],
                'coverage'      => $result['coverage'],
                'user_id'       => $identity['user_id'],
                'subscriber_id' => $identity['contact'] ? (int) $identity['contact']->id : 0,
                'email'         => $identity['email'],
            ];
        }

        return [
            'state'   => [
                'union'        => $result['union'],
                'watched'      => $result['watched'],
                'ts'           => $now,
                'fired'        => $newFired,
                // preserve the layer handler's keys — both handlers share this state row
                'layers'       => Arr::get($state, 'layers', []),
                'layers_fired' => Arr::get($state, 'layers_fired', []),
            ],
            'fired'   => $ctxs,
            'reached' => $newFired,
            'extra'   => ['coverage' => round($result['coverage'], 4)],
        ];
    }

    protected function resolveServerDuration($mediaId)
    {
        return \FluentPlayer\App\Integrations\FluentCrm\Helper::serverDuration($mediaId);
    }
}
