<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

/**
 * Forwards timeline-armed behavior events into FluentCRM Event Tracking so
 * they appear on the contact profile timeline. Consumes the same public
 * actions the funnel triggers do, but only fires the timeline settings
 * actually track — an automation-armed event on a non-timeline media never
 * logs. Tracker failures are swallowed: the reporting endpoint and funnel
 * processing must never notice this bridge.
 */
class TimelineBridge
{
    public static function register()
    {
        add_action(BehaviorRegistry::TRIGGER_MILESTONE, [new static(), 'onMilestone'], 20);
        add_action(BehaviorRegistry::TRIGGER_LAYER, [new static(), 'onLayerEvent'], 20);
    }

    const MILESTONE_KEYS = [
        'started'   => 'fp_media_started',
        'q25'       => 'fp_watched_25_percent',
        'q50'       => 'fp_watched_50_percent',
        'q75'       => 'fp_watched_75_percent',
        'completed' => 'fp_media_completed',
    ];

    public function onMilestone($ctx)
    {
        $ctx = (array) $ctx;
        if (!TimelineArmingSource::matchesMilestone($ctx)) {
            return;
        }

        $mediaTitle = $this->mediaTitle($ctx);
        $milestone = (string) Arr::get($ctx, 'milestone', '');

        $labels = [
            'started' => __('Started watching "%s"', 'fluent-player'),
            'q25'     => __('Watched 25%% of "%s"', 'fluent-player'),
            'q50'     => __('Watched 50%% of "%s"', 'fluent-player'),
            'q75'     => __('Watched 75%% of "%s"', 'fluent-player'),
            'completed' => __('Completed "%s"', 'fluent-player'),
        ];
        if (!isset($labels[$milestone])) {
            return;
        }

        $this->send([
            'event_key' => self::MILESTONE_KEYS[$milestone],
            'title'     => sprintf($labels[$milestone], $mediaTitle),
            'value'     => $this->milestoneValue($ctx, $milestone),
        ], $ctx + ['media_title' => $mediaTitle, 'event' => $milestone]);
    }

    /**
     * Value line carries only what the title doesn't: verified watch amount,
     * completion policy, and the exact-match media id.
     */
    protected function milestoneValue(array $ctx, $milestone)
    {
        $mediaId = (int) Arr::get($ctx, 'media_id');
        $duration = $this->mediaDuration($mediaId);
        $parts = [];

        if ($milestone === 'started') {
            if ($duration > 0) {
                $type = (string) Arr::get(\FluentPlayer\App\Models\Media::getMediaSettings($mediaId), 'mediaType', '');
                $parts[] = sprintf(
                    /* translators: 1: human duration, 2: "video" or "audio" */
                    __('%1$s %2$s', 'fluent-player'),
                    self::humanTime($duration),
                    $type === 'audio' ? __('audio', 'fluent-player') : __('video', 'fluent-player')
                );
            }
        } elseif ($duration > 0) {
            $coverage = (float) Arr::get($ctx, 'coverage', 0);
            if ($coverage > 0) {
                $parts[] = sprintf(
                    /* translators: 1: verified watched time, 2: media duration */
                    __('~%1$s of %2$s watched', 'fluent-player'),
                    self::humanTime($coverage * $duration),
                    self::humanTime($duration)
                );
            }
        }

        if ($milestone === 'completed' && Arr::get($ctx, 'boundary') !== null) {
            /* translators: %d: completion threshold percentage */
            $parts[] = sprintf(__('%d%% threshold', 'fluent-player'), round((float) $ctx['boundary'] * 100));
        }

        $parts[] = '#' . $mediaId;

        return implode(' · ', $parts);
    }

    public function onLayerEvent($ctx)
    {
        $ctx = (array) $ctx;
        if (!TimelineArmingSource::matchesLayer($ctx)) {
            return;
        }

        $mediaTitle = $this->mediaTitle($ctx);
        $event = (string) Arr::get($ctx, 'event', '');
        $layerTitle = (string) Arr::get($ctx, 'layer_title', '');
        if ($layerTitle === '') {
            $layerTitle = $this->layerLabel($ctx);
        }

        $labels = [
            'seen'      => __('Saw "%1$s" on "%2$s"', 'fluent-player'),
            'completed' => __('Completed "%1$s" on "%2$s"', 'fluent-player'),
            'skipped'   => __('Skipped "%1$s" on "%2$s"', 'fluent-player'),
        ];
        if (!isset($labels[$event])) {
            return;
        }

        $this->send([
            'event_key' => 'fp_layer_' . $event,
            'title'     => sprintf($labels[$event], $layerTitle, $mediaTitle),
            'value'     => $this->layerValue($ctx),
        ], $ctx + ['media_title' => $mediaTitle]);
    }

    /**
     * Editor-parity label for the layer (provider name, form title, "Ad #Video"),
     * derived from the full layer definition — the same cached source layerValue()
     * reads. Falls back to the friendly type label when the layer is gone or its
     * type has no specific label.
     */
    protected function layerLabel(array $ctx)
    {
        $layerId = (string) Arr::get($ctx, 'layer_id');
        foreach (BehaviorRegistry::mediaLayers((int) Arr::get($ctx, 'media_id')) as $layer) {
            if ((string) Arr::get((array) $layer, 'id') === $layerId) {
                $label = Helper::layerDisplayLabel((array) $layer);
                if ($label !== '') {
                    return $label;
                }
                break;
            }
        }

        $type = (string) Arr::get($ctx, 'layer_type', '');
        return (string) Arr::get(Helper::layerTypeLabels(), $type, ucfirst($type));
    }

    /**
     * "{Type} at {position} · #{media_id}" — type and position are the facts
     * the title doesn't carry.
     */
    protected function layerValue(array $ctx)
    {
        $mediaId = (int) Arr::get($ctx, 'media_id');
        $type = (string) Arr::get($ctx, 'layer_type', '');
        $typeLabel = Arr::get(Helper::layerTypeLabels(), $type, ucfirst($type));

        $displayTime = null;
        foreach (BehaviorRegistry::mediaLayers($mediaId) as $layer) {
            if ((string) Arr::get((array) $layer, 'id') === (string) Arr::get($ctx, 'layer_id')) {
                $displayTime = (int) Arr::get((array) $layer, 'displayTime', 0);
                break;
            }
        }

        $parts = [];
        if ($displayTime !== null && $displayTime > 0) {
            /* translators: 1: layer type label, 2: position in the media (mm:ss) */
            $parts[] = sprintf(__('%1$s at %2$s', 'fluent-player'), $typeLabel, gmdate($displayTime >= 3600 ? 'H:i:s' : 'i:s', $displayTime));
        } elseif ($typeLabel !== '') {
            $parts[] = $typeLabel;
        }
        $parts[] = '#' . $mediaId;

        return implode(' · ', $parts);
    }

    protected function mediaDuration($mediaId)
    {
        $settings = \FluentPlayer\App\Models\Media::getMediaSettings((int) $mediaId);
        $duration = (float) Arr::get($settings, 'duration', 0);
        if ($duration <= 0) {
            $provider = (string) Arr::get($settings, 'provider', '');
            $duration = (float) Arr::get($settings, $provider . '.duration', 0);
        }
        return $duration;
    }

    public static function humanTime($seconds)
    {
        $seconds = (int) round($seconds);
        if ($seconds < 60) {
            /* translators: %d: number of seconds */
            return sprintf(__('%d sec', 'fluent-player'), max($seconds, 1));
        }
        if ($seconds < 3600) {
            /* translators: %d: number of minutes */
            return sprintf(__('%d min', 'fluent-player'), (int) round($seconds / 60));
        }
        /* translators: 1: hours, 2: zero-padded minutes */
        return sprintf(__('%1$dh %2$02dm', 'fluent-player'), (int) floor($seconds / 3600), (int) round(($seconds % 3600) / 60));
    }

    protected function send(array $payload, array $ctx)
    {
        if (!function_exists('FluentCrmApi')) {
            return;
        }

        $canonical = [
            'provider'  => 'fluent-player',
            'event_key' => $payload['event_key'],
        ];
        $payload = array_merge(['provider' => 'fluent-player'], $payload);

        /**
         * Customize the timeline entry's title/value or veto it (return falsy).
         * event_key and provider are locked: CRM segments and automation
         * conditions match on them, and the tracker merge-keys on event_key —
         * mutations are restored to canonical after the filter. Sites needing
         * a custom vocabulary should veto here and call
         * FluentCrmApi('event_tracker')->track() from the public behavior actions.
         */
        $payload = apply_filters('fluent_player/fluentcrm_timeline_event_payload', $payload, $ctx);
        if (!$payload || !is_array($payload)) {
            return;
        }
        $payload = array_merge($payload, $canonical);

        $subscriberId = (int) Arr::get($ctx, 'subscriber_id', 0);
        if ($subscriberId > 0) {
            $payload['subscriber_id'] = $subscriberId;
        } elseif (Arr::get($ctx, 'email')) {
            $payload['email'] = (string) $ctx['email'];
        } elseif (Arr::get($ctx, 'user_id')) {
            $payload['user_id'] = (int) $ctx['user_id'];
        } else {
            return;
        }

        try {
            FluentCrmApi('event_tracker')->track($payload, false);
        } catch (\Throwable $e) {
            // timeline logging must never disturb reporting or funnels
        }
    }

    protected function mediaTitle(array $ctx)
    {
        $title = get_the_title((int) Arr::get($ctx, 'media_id'));
        return $title !== '' ? html_entity_decode($title, ENT_QUOTES | ENT_HTML5) : ('#' . (int) Arr::get($ctx, 'media_id'));
    }
}
