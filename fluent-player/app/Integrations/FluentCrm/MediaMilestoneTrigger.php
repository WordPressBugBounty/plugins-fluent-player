<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentPlayer\Framework\Support\Arr;

/**
 * Starts a FluentCRM funnel when a viewer reaches a media milestone
 * (started / 25% / 50% / 75% / completed), server-verified. Fires off the
 * `fluent_player/media_milestone` action the milestone endpoint emits.
 */
class MediaMilestoneTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = BehaviorRegistry::TRIGGER_MILESTONE;
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Fluent Player', 'fluent-player'),
            'label'       => __('Media Milestone Reached', 'fluent-player'),
            'description' => __('This funnel starts when a viewer reaches a watch milestone on a media', 'fluent-player'),
            'icon'        => 'fc-icon-flp-brand',
            'svg'         => Helper::iconSvg(),
        ];
    }

    public function prepareEditorDetails($funnel)
    {
        // seed settings from legacy conditions-stored fields so pre-move funnels
        // display and re-save their real values instead of defaults
        $legacy = Arr::only(
            (array) $funnel->conditions,
            ['media_ids', 'milestone', 'completion_threshold', 'run_multiple']
        );
        $funnel->settings = wp_parse_args((array) $funnel->settings, $legacy);

        return parent::prepareEditorDetails($funnel);
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'media_ids'            => [],
            'milestone'            => 'completed',
            'completion_threshold' => '0.9',
            'on_non_contact'       => 'skip',
            'run_multiple'         => 'no',
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Viewer reaches a media milestone', 'fluent-player'),
            'sub_title' => __('This funnel starts when a viewer watches a Fluent Player media to the selected milestone', 'fluent-player'),
            'fields'    => array_merge(
                Helper::milestoneTargetingFields(),
                Helper::entryFields(__('Who can enter', 'fluent-player')),
                Helper::runMultipleFields(
                    __('A milestone fires once per media, so re-watching never repeats it.', 'fluent-player'),
                    __('Allow contacts to re-enter when another targeted media reaches this milestone', 'fluent-player'),
                    __('Runs once per contact: the first targeted media to fire enters them, later ones are skipped — good for one-time journeys like welcome emails or coupons.', 'fluent-player')
                )
            ),
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'update_type' => 'update',
        ];
    }

    public function getConditionFields($funnel)
    {
        return Helper::updateTypeConditionFields();
    }

    public function handle($funnel, $originalArgs)
    {
        $ctx = Arr::get($originalArgs, 0, []);
        $config = wp_parse_args((array) $funnel->settings, (array) $funnel->conditions);

        Helper::handleTriggerEvent($funnel, $ctx, Helper::milestoneMatches($config, $ctx), $this->triggerName);
    }
}
