<?php

namespace FluentPlayer\App\Services\Behavior;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

/**
 * Destination-neutral builders for "log playback events" settings blocks.
 * FluentCRM's integration page composes these today; a future destination
 * (e.g. Fluent Cart) reuses them with its own key prefix and storage.
 */
class TimelineFields
{
    const MILESTONES = ['started', 'q25', 'q50', 'q75', 'completed'];
    const LAYER_EVENTS = ['seen', 'completed', 'skipped'];

    /**
     * Canonical threshold options — the automation drawer delegates here.
     */
    public static function thresholdPairs()
    {
        return [
            ['id' => '0.7', 'title' => '70%'],
            ['id' => '0.8', 'title' => '80%'],
            ['id' => '0.9', 'title' => '90%'],
            ['id' => '0.95', 'title' => '95%'],
            ['id' => '1', 'title' => '100%'],
        ];
    }

    public static function thresholdHelpText()
    {
        return __('90% works well for most media since outros are often skipped. Choose 100% only when full playback must be verified, such as compliance training.', 'fluent-player');
    }

    public static function thresholdValues()
    {
        return array_column(self::thresholdPairs(), 'id');
    }

    public static function milestonePairs()
    {
        return [
            ['id' => 'started', 'title' => __('Started', 'fluent-player')],
            ['id' => 'q25', 'title' => __('25%', 'fluent-player')],
            ['id' => 'q50', 'title' => __('50%', 'fluent-player')],
            ['id' => 'q75', 'title' => __('75%', 'fluent-player')],
            ['id' => 'completed', 'title' => __('Completed', 'fluent-player')],
        ];
    }

    public static function layerEventPairs()
    {
        return [
            ['id' => 'seen', 'title' => __('Seen', 'fluent-player')],
            ['id' => 'completed', 'title' => __('Completed', 'fluent-player')],
            ['id' => 'skipped', 'title' => __('Skipped', 'fluent-player')],
        ];
    }

    /**
     * Layer TYPE vocabulary for the All-media scope filter. Mirrors the
     * behavior event taxonomy (hotspots emit no layer events). Pro-only
     * types are hidden on free installs — those layers cannot exist there.
     */
    public static function layerTypePairs()
    {
        $pairs = [
            ['id' => 'email', 'title' => __('Email Capture', 'fluent-player')],
            ['id' => 'form', 'title' => __('Form', 'fluent-player')],
        ];

        if (defined('FLUENT_PLAYER_PRO_VERSION')) {
            $pairs[] = ['id' => 'cta', 'title' => __('CTA', 'fluent-player')];
            $pairs[] = ['id' => 'ad', 'title' => __('Ad', 'fluent-player')];
            $pairs[] = ['id' => 'shortcode', 'title' => __('Shortcode', 'fluent-player')];
        }

        return $pairs;
    }

    /**
     * Full vocabulary regardless of Pro state — a saved pro type must
     * survive sanitize while Pro is temporarily deactivated.
     */
    public static function layerTypeValues()
    {
        return ['email', 'form', 'cta', 'ad', 'shortcode'];
    }

    public static function defaults($prefix = 'timeline_')
    {
        return [
            $prefix . 'enabled'              => false,
            $prefix . 'scope'                => 'selected',
            $prefix . 'media_ids'            => [],
            $prefix . 'milestones'           => self::MILESTONES,
            $prefix . 'completion_threshold' => '0.9',
            $prefix . 'layer_events'         => ['completed'],
            $prefix . 'layer_scope'          => 'all',
            $prefix . 'layer_type_scope'     => 'all',
            $prefix . 'layer_types'          => [],
            $prefix . 'layer_ids'            => [],
        ];
    }

    /**
     * Settings-page field definitions (ConfigFormFields schema). The
     * destination passes its own async endpoints for the two pickers.
     */
    public static function settingsFields($mediaEndpoint, $layersEndpoint, $prefix = 'timeline_')
    {
        $enabled = $prefix . 'enabled';

        return [
            [
                'key'   => $enabled,
                'type'  => 'switch',
                'label' => __('Log playback to contact timeline', 'fluent-player'),
                'help'  => __('Records watch milestones and layer interactions on the contact profile — once per contact per media.', 'fluent-player'),
            ],
            [
                'key'        => $prefix . 'scope',
                'default'    => 'selected',
                'type'       => 'radio',
                'label'      => __('Which media', 'fluent-player'),
                'options'    => self::toOptions([
                    ['id' => 'selected', 'title' => __('Selected media', 'fluent-player')],
                    ['id' => 'all', 'title' => __('All media', 'fluent-player')],
                ]),
                'indent'     => true,
                'depends_on' => $enabled,
            ],
            [
                'key'        => $prefix . 'scope_all_note',
                'type'       => 'notice',
                'variant'    => 'info',
                'text'       => __('Every media on the site will log to timelines, including media added later. For large libraries, Selected media keeps contact timelines meaningful.', 'fluent-player'),
                'indent'     => true,
                'depends_on' => ['all' => [$enabled, ['key' => $prefix . 'scope', 'value' => 'all']]],
            ],
            [
                'key'        => $prefix . 'media_ids',
                'default'    => [],
                'type'       => 'media_select',
                'label'      => __('Media', 'fluent-player'),
                'multiple'   => true,
                'placeholder' => __('Type to search media…', 'fluent-player'),
                'empty_text' => __('No media found.', 'fluent-player'),
                'endpoint'   => $mediaEndpoint,
                'indent'     => true,
                'depends_on' => ['all' => [$enabled, ['key' => $prefix . 'scope', 'value' => 'selected']]],
            ],
            [
                'key'        => $prefix . 'milestones',
                'default'    => self::MILESTONES,
                'type'       => 'checkbox_group',
                'label'      => __('Milestones', 'fluent-player'),
                'options'    => self::toOptions(self::milestonePairs()),
                'indent'     => true,
                'depends_on' => $enabled,
            ],
            [
                'key'        => $prefix . 'completion_threshold',
                'default'    => '0.9',
                'type'       => 'radio',
                'label'      => __('Completion Threshold', 'fluent-player'),
                'options'    => self::toOptions(self::thresholdPairs()),
                'indent'     => true,
                'depends_on' => ['all' => [$enabled, ['key' => $prefix . 'milestones', 'contains' => 'completed']]],
            ],
            [
                'key'        => $prefix . 'layer_events',
                'default'    => ['completed'],
                'type'       => 'checkbox_group',
                'label'      => __('Layer events', 'fluent-player'),
                'options'    => self::toOptions(self::layerEventPairs()),
                'indent'     => true,
                'depends_on' => $enabled,
            ],
            [
                'key'        => $prefix . 'nothing_tracked_note',
                'type'       => 'notice',
                'variant'    => 'warning',
                'text'       => __('Nothing will be logged — select at least one milestone or layer event.', 'fluent-player'),
                'indent'     => true,
                'depends_on' => ['all' => [
                    $enabled,
                    ['key' => $prefix . 'milestones', 'empty' => true],
                    ['key' => $prefix . 'layer_events', 'empty' => true],
                ]],
            ],
            [
                'key'        => $prefix . 'layer_scope',
                'default'    => 'all',
                'type'       => 'radio',
                'label'      => __('Which layers', 'fluent-player'),
                'options'    => self::toOptions([
                    ['id' => 'all', 'title' => __('All layers on selected media', 'fluent-player')],
                    ['id' => 'specific', 'title' => __('Specific layers', 'fluent-player')],
                ]),
                'indent'     => true,
                'depends_on' => ['all' => [
                    $enabled,
                    ['key' => $prefix . 'scope', 'value' => 'selected'],
                    ['key' => $prefix . 'media_ids', 'nonempty' => true],
                    ['key' => $prefix . 'layer_events', 'nonempty' => true],
                ]],
            ],
            [
                'key'        => $prefix . 'layer_ids',
                'default'    => [],
                'type'       => 'media_select',
                'label'      => __('Layers', 'fluent-player'),
                'multiple'   => true,
                'grouped'    => true,
                'placeholder' => __('Search layers…', 'fluent-player'),
                'empty_text' => __('No layers found on the selected media.', 'fluent-player'),
                'endpoint'   => $layersEndpoint,
                'depends_from' => $prefix . 'media_ids',
                'indent'     => true,
                'depends_on' => ['all' => [
                    $enabled,
                    ['key' => $prefix . 'scope', 'value' => 'selected'],
                    ['key' => $prefix . 'media_ids', 'nonempty' => true],
                    ['key' => $prefix . 'layer_scope', 'value' => 'specific'],
                    ['key' => $prefix . 'layer_events', 'nonempty' => true],
                ]],
            ],
            [
                'key'        => $prefix . 'layer_type_scope',
                'default'    => 'all',
                'type'       => 'radio',
                'label'      => __('Which layers', 'fluent-player'),
                'options'    => self::toOptions([
                    ['id' => 'all', 'title' => __('All layers', 'fluent-player')],
                    ['id' => 'types', 'title' => __('Specific layer types', 'fluent-player')],
                ]),
                'indent'     => true,
                'depends_on' => ['all' => [
                    $enabled,
                    ['key' => $prefix . 'scope', 'value' => 'all'],
                    ['key' => $prefix . 'layer_events', 'nonempty' => true],
                ]],
            ],
            [
                'key'        => $prefix . 'layer_types',
                'default'    => [],
                'type'       => 'checkbox_group',
                'label'      => __('Layer types', 'fluent-player'),
                'options'    => self::toOptions(self::layerTypePairs()),
                'indent'     => true,
                'depends_on' => ['all' => [
                    $enabled,
                    ['key' => $prefix . 'scope', 'value' => 'all'],
                    ['key' => $prefix . 'layer_type_scope', 'value' => 'types'],
                    ['key' => $prefix . 'layer_events', 'nonempty' => true],
                ]],
            ],
        ];
    }

    /**
     * Embed value→label pairs for SAVED picker values into the field defs so
     * the page renders chips without any picker request. Also the only place
     * a stale saved value gets a human "(deleted)" label.
     */
    public static function decorateSelectedOptions(array $fields, array $settings, $prefix = 'timeline_')
    {
        foreach ($fields as &$field) {
            if ($field['key'] === $prefix . 'media_ids') {
                $field['selected_options'] = self::mediaSelectedOptions(Arr::get($settings, $prefix . 'media_ids', []));
            } elseif ($field['key'] === $prefix . 'layer_ids') {
                $field['selected_options'] = self::layerSelectedOptions(Arr::get($settings, $prefix . 'layer_ids', []));
            }
        }
        return $fields;
    }

    protected static function mediaSelectedOptions($ids)
    {
        $options = [];
        foreach (array_values(array_filter(array_map('absint', (array) $ids))) as $id) {
            $title = (string) get_the_title($id);
            $options[] = [
                'value' => $id,
                'label' => $title !== ''
                    ? html_entity_decode($title, ENT_QUOTES | ENT_HTML5)
                    /* translators: %d: media id whose post no longer exists */
                    : sprintf(__('#%d (deleted)', 'fluent-player'), $id),
            ];
        }
        return $options;
    }

    protected static function layerSelectedOptions($encodedIds)
    {
        $options = [];
        foreach ((array) $encodedIds as $encoded) {
            if (!is_string($encoded) || !preg_match('/^(\d+):(.+)$/', $encoded, $m)) {
                continue;
            }
            $mediaId = (int) $m[1];
            $layerId = $m[2];

            $label = null;
            foreach ((array) Arr::get(\FluentPlayer\App\Models\Media::getMediaSettings($mediaId), 'layers', []) as $layer) {
                if ((string) Arr::get((array) $layer, 'id') === $layerId) {
                    $label = trim(html_entity_decode((string) Arr::get((array) $layer, 'title', ''), ENT_QUOTES | ENT_HTML5));
                    break;
                }
            }

            $mediaTitle = (string) get_the_title($mediaId);
            if ($label === null) {
                /* translators: %s: stored layer id whose layer no longer exists */
                $label = sprintf(__('%s (deleted)', 'fluent-player'), $layerId);
            } elseif ($label === '') {
                $label = $layerId;
            }
            if ($mediaTitle !== '') {
                $label = html_entity_decode($mediaTitle, ENT_QUOTES | ENT_HTML5) . ' — ' . $label;
            }

            $options[] = ['value' => $encoded, 'label' => $label];
        }
        return $options;
    }

    /**
     * Whitelist enums, prune orphans. Runs after the generic sanitizer.
     */
    public static function sanitize(array $settings, $prefix = 'timeline_')
    {
        $defaults = self::defaults($prefix);

        $settings[$prefix . 'enabled'] = Arr::isTrue($settings, $prefix . 'enabled');

        $settings[$prefix . 'scope'] = self::pick($settings, $prefix . 'scope', ['selected', 'all'], 'selected');
        $settings[$prefix . 'layer_scope'] = self::pick($settings, $prefix . 'layer_scope', ['all', 'specific'], 'all');
        $settings[$prefix . 'layer_type_scope'] = self::pick($settings, $prefix . 'layer_type_scope', ['all', 'types'], 'all');
        $settings[$prefix . 'completion_threshold'] = self::pick($settings, $prefix . 'completion_threshold', self::thresholdValues(), '0.9');

        $settings[$prefix . 'milestones'] = self::intersect($settings, $prefix . 'milestones', self::MILESTONES, $defaults[$prefix . 'milestones']);
        $settings[$prefix . 'layer_events'] = self::intersect($settings, $prefix . 'layer_events', self::LAYER_EVENTS, []);
        $settings[$prefix . 'layer_types'] = self::intersect($settings, $prefix . 'layer_types', self::layerTypeValues(), []);

        // partial saves may omit media_ids; pruning against a missing list
        // would wipe every stored layer selection
        $hasMediaList = array_key_exists($prefix . 'media_ids', $settings);
        $mediaIds = array_values(array_filter(array_map('absint', (array) Arr::get($settings, $prefix . 'media_ids', []))));
        $settings[$prefix . 'media_ids'] = $mediaIds;

        $layerIds = [];
        foreach ((array) Arr::get($settings, $prefix . 'layer_ids', []) as $encoded) {
            if (!is_string($encoded) && !is_numeric($encoded)) {
                continue;
            }
            $encoded = (string) $encoded;
            if (!preg_match('/^(\d+):(.+)$/', $encoded, $m)) {
                continue;
            }
            if ($hasMediaList && !in_array((int) $m[1], $mediaIds, true)) {
                continue;
            }
            $layerIds[] = (int) $m[1] . ':' . sanitize_text_field($m[2]);
        }
        $settings[$prefix . 'layer_ids'] = array_values(array_unique($layerIds));

        return $settings;
    }

    private static function pick($settings, $key, array $allowed, $fallback)
    {
        $value = (string) Arr::get($settings, $key, $fallback);
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function intersect($settings, $key, array $allowed, array $fallback)
    {
        $value = Arr::get($settings, $key, null);
        if (!is_array($value)) {
            return $fallback;
        }
        return array_values(array_intersect(array_map('strval', $value), $allowed));
    }

    private static function toOptions(array $pairs)
    {
        return array_map(function ($pair) {
            return ['value' => $pair['id'], 'label' => $pair['title']];
        }, $pairs);
    }
}
