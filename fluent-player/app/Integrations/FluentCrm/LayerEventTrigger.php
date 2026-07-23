<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentPlayer\Framework\Support\Arr;

/**
 * Starts a FluentCRM funnel when a viewer sees, completes or skips a media
 * layer (ad, CTA, form, email capture). Fires off the
 * `fluent_player/layer_event` action the layer endpoint emits.
 */
class LayerEventTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = BehaviorRegistry::TRIGGER_LAYER;
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Fluent Player', 'fluent-player'),
            'label'       => __('Media Layer Interaction', 'fluent-player'),
            'description' => __('This funnel starts when a viewer sees, completes or skips a media layer (ad, CTA, form, etc.)', 'fluent-player'),
            'icon'        => 'fc-icon-flp-brand',
            'svg'         => Helper::iconSvg(),
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'layer_ids'      => [],
            'layer_event'    => 'completed',
            'on_non_contact' => 'skip',
            'run_multiple'   => 'no',
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Viewer interacts with a media layer', 'fluent-player'),
            'sub_title' => __('This funnel starts when a viewer sees, completes or skips one of the selected layers', 'fluent-player'),
            'fields'    => array_merge(
                Helper::layerTargetingFields(),
                Helper::entryFields(__('Who can enter', 'fluent-player')),
                Helper::runMultipleFields(
                    __('A layer event fires once per layer, so repeat views never repeat it.', 'fluent-player'),
                    __('Allow contacts to re-enter when another targeted layer fires this event', 'fluent-player'),
                    __('Runs once per contact: the first targeted layer to fire enters them, later ones are skipped — good for one-time journeys like welcome emails or coupons.', 'fluent-player')
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

        Helper::handleTriggerEvent($funnel, $ctx, Helper::layerMatches($config, $ctx), $this->triggerName);
    }
}
