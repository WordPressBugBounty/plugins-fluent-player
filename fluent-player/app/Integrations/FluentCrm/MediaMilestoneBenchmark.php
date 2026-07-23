<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentPlayer\Framework\Support\Arr;

/**
 * A goal/waypoint inside a running funnel: advance (or let a contact enter) when
 * they reach a media milestone. Second consumer of the same
 * `fluent_player/media_milestone` event — no new emission or endpoint.
 */
class MediaMilestoneBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = BehaviorRegistry::TRIGGER_MILESTONE;
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('Fluent Player', 'fluent-player'),
            'title'       => __('Media Milestone Reached', 'fluent-player'),
            'description' => __('Runs when a contact reaches a watch milestone on a media', 'fluent-player'),
            'icon'        => 'fc-icon-flp-brand',
            'svg'         => Helper::iconSvg(),
            'settings'    => [
                'media_ids'            => [],
                'milestone'            => 'completed',
                'completion_threshold' => '0.9',
                'on_non_contact'       => 'skip',
                'type'                 => 'optional',
                'can_enter'            => 'yes',
            ],
        ];
    }

    public function getBlockFields($funnel)
    {
        $fields = array_merge(
            Helper::milestoneTargetingFields(),
            Helper::entryFields(__('Entry rules', 'fluent-player'))
        );

        $fields['type'] = $this->benchmarkTypeField();
        $fields['can_enter'] = $this->canEnterField();
        $fields['goal_tip'] = [
            'type' => 'html',
            'info' => Helper::infoLine(__('Tip: goals can also react to inaction — pair this goal with a Wait step to follow up with contacts who never reach the milestone.', 'fluent-player')),
        ];

        return [
            'title'     => __('Media Milestone Reached', 'fluent-player'),
            'sub_title' => __('This goal completes when a contact watches the selected media to the milestone', 'fluent-player'),
            'fields'    => $fields,
        ];
    }

    public function handle($benchMark, $originalArgs)
    {
        $ctx = Arr::get($originalArgs, 0, []);

        Helper::handleBenchmarkEvent($benchMark, $ctx, Helper::milestoneMatches($benchMark->settings, $ctx), $this->triggerName);
    }
}
