<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentPlayer\Framework\Support\Arr;

/**
 * A goal/waypoint inside a running funnel: advance (or let a contact enter) when
 * they see, complete or skip a media layer. Second consumer of the same
 * `fluent_player/layer_event` event — no new emission or endpoint.
 */
class LayerEventBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = BehaviorRegistry::TRIGGER_LAYER;
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('Fluent Player', 'fluent-player'),
            'title'       => __('Media Layer Interaction', 'fluent-player'),
            'description' => __('Runs when a contact sees, completes or skips a media layer', 'fluent-player'),
            'icon'        => 'fc-icon-flp-brand',
            'svg'         => Helper::iconSvg(),
            'settings'    => [
                'layer_ids'      => [],
                'layer_event'    => 'completed',
                'on_non_contact' => 'skip',
                'type'           => 'optional',
                'can_enter'      => 'yes',
            ],
        ];
    }

    public function getBlockFields($funnel)
    {
        $fields = array_merge(
            Helper::layerTargetingFields(),
            Helper::entryFields(__('Entry rules', 'fluent-player'))
        );

        $fields['type'] = $this->benchmarkTypeField();
        $fields['can_enter'] = $this->canEnterField();
        $fields['goal_tip'] = [
            'type' => 'html',
            'info' => Helper::infoLine(__('Tip: goals can also react to inaction — pair this goal with a Wait step to follow up with contacts who never reach it.', 'fluent-player')),
        ];

        return [
            'title'     => __('Media Layer Interaction', 'fluent-player'),
            'sub_title' => __('This goal completes when a contact sees, completes or skips one of the selected layers', 'fluent-player'),
            'fields'    => $fields,
        ];
    }

    public function handle($benchMark, $originalArgs)
    {
        $ctx = Arr::get($originalArgs, 0, []);

        Helper::handleBenchmarkEvent($benchMark, $ctx, Helper::layerMatches($benchMark->settings, $ctx), $this->triggerName);
    }
}
