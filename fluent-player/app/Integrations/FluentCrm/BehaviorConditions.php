<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

class BehaviorConditions
{
    const WATCH_THRESHOLDS = [
        'fp_watched_50' => 0.5,
        'fp_watched_75' => 0.75,
        'fp_watched_90' => 0.9,
    ];

    const LAYER_EVENTS = [
        'fp_layer_seen'      => 'seen',
        'fp_layer_completed' => 'completed',
        'fp_layer_skipped'   => 'skipped',
    ];

    public function register()
    {
        add_filter('fluentcrm_automation_condition_groups', [$this, 'addConditionGroup']);
        add_filter('fluentcrm_automation_conditions_assess_fluent_player', [$this, 'assess'], 10, 3);
        add_filter('fluentcrm_ajax_options_fp_media_options', [$this, 'mediaOptions'], 10, 3);
        add_filter('fluentcrm_ajax_options_fp_layer_options', [$this, 'layerOptions'], 10, 3);

        return $this;
    }

    public function addConditionGroup($groups)
    {
        $children = [
            $this->watchItem('fp_watched_50', __('Watched Media ≥ 50%', 'fluent-player')),
            $this->watchItem('fp_watched_75', __('Watched Media ≥ 75%', 'fluent-player')),
            $this->watchItem('fp_watched_90', __('Watched Media ≥ 90%', 'fluent-player')),
            $this->layerItem('fp_layer_seen', __('Layer Seen', 'fluent-player')),
            $this->layerItem('fp_layer_completed', __('Layer Completed', 'fluent-player')),
            $this->layerItem('fp_layer_skipped', __('Layer Skipped', 'fluent-player')),
        ];

        $groups['fluent_player'] = [
            'label'    => __('Fluent Player', 'fluent-player'),
            'value'    => 'fluent_player',
            'children' => $children,
        ];

        return $groups;
    }

    protected function watchItem($value, $label)
    {
        return [
            'value'            => $value,
            'label'            => $label,
            'type'             => 'selections',
            'component'        => 'ajax_selector',
            'option_key'       => 'fp_media_options',
            'is_multiple'      => true,
            'custom_operators' => [
                'exist'     => __('Yes', 'fluent-player'),
                'not_exist' => __('No', 'fluent-player'),
            ],
            'help'             => __('Server-verified watch coverage of the selected media — skipping ahead does not count', 'fluent-player'),
        ];
    }

    protected function layerItem($value, $label)
    {
        return [
            'value'            => $value,
            'label'            => $label,
            'type'             => 'selections',
            'component'        => 'ajax_selector',
            'option_key'       => 'fp_layer_options',
            'is_multiple'      => true,
            'custom_operators' => [
                'exist'     => __('Yes', 'fluent-player'),
                'not_exist' => __('No', 'fluent-player'),
            ],
            'help'             => __('Matches contacts who have this interaction recorded for a selected layer', 'fluent-player'),
        ];
    }

    public function mediaOptions($items, $search = '', $ids = [])
    {
        $options = Helper::mediaOptions();
        if ($search) {
            $options = array_values(array_filter($options, function ($option) use ($search) {
                return stripos($option['title'], $search) !== false;
            }));
        }
        return array_merge(is_array($items) ? $items : [], $options);
    }

    public function layerOptions($items, $search = '', $ids = [])
    {
        return array_merge(is_array($items) ? $items : [], Helper::flatLayerOptions((string) $search));
    }

    public function assess($result, $conditions, $subscriber)
    {
        foreach ((array) $conditions as $condition) {
            $key = Arr::get($condition, 'data_key');
            $operator = Arr::get($condition, 'operator');
            $values = Arr::get($condition, 'data_value', []);
            $values = is_array($values) ? $values : [$values];

            if (Arr::exists(self::WATCH_THRESHOLDS, $key)) {
                $matched = $this->anyMediaWatched($subscriber, $values, self::WATCH_THRESHOLDS[$key]);
            } elseif (Arr::exists(self::LAYER_EVENTS, $key)) {
                $matched = $this->anyLayerEvent($subscriber, $values, self::LAYER_EVENTS[$key]);
            } else {
                continue;
            }

            $expected = ($operator !== 'not_exist');
            if ($matched !== $expected) {
                return false;
            }
        }

        return $result;
    }

    protected function anyMediaWatched($subscriber, $mediaIds, $threshold)
    {
        foreach ($mediaIds as $mediaId) {
            $mediaId = (int) $mediaId;
            if (!$mediaId) {
                continue;
            }

            $state = $this->stateFor($subscriber, $mediaId);
            if (!$state) {
                continue;
            }

            $duration = $this->mediaDuration($mediaId);
            if ($duration <= 0) {
                continue;
            }

            if (((float) Arr::get($state, 'watched', 0) / $duration) >= $threshold) {
                return true;
            }
        }
        return false;
    }

    protected function anyLayerEvent($subscriber, $encodedIds, $event)
    {
        foreach ($encodedIds as $encoded) {
            $parts = explode(':', (string) $encoded, 2);
            $mediaId = (int) Arr::get($parts, 0, 0);
            $layerId = (string) Arr::get($parts, 1, '');
            if (!$mediaId || $layerId === '') {
                continue;
            }

            $state = $this->stateFor($subscriber, $mediaId);
            if ($state && Arr::get($state, "layers.{$layerId}.{$event}")) {
                return true;
            }
        }
        return false;
    }

    protected function stateFor($subscriber, $mediaId)
    {
        $meta = fluentcrm_get_meta($subscriber->id, 'FluentPlayerBehavior', BehaviorState::metaKey($mediaId));
        if ($meta && !empty($meta->value)) {
            $state = json_decode($meta->value, true);
            if (is_array($state)) {
                return $state;
            }
        }

        if (!empty($subscriber->user_id)) {
            $state = get_user_meta($subscriber->user_id, BehaviorState::metaKey($mediaId), true);
            if (is_array($state)) {
                return $state;
            }
        }

        return null;
    }

    protected function mediaDuration($mediaId)
    {
        return Helper::serverDuration($mediaId);
    }
}
