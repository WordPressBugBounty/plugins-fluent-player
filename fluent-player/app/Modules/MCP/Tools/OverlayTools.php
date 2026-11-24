<?php

namespace FluentPlayer\App\Modules\MCP\Tools;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\EmailCollection;
use FluentPlayer\App\Modules\MCP\Support\ErrorCodes;
use FluentPlayer\App\Modules\MCP\Support\MCPHelper;
use FluentPlayer\App\Modules\MCP\Support\WriteGuard;
use FluentPlayer\App\Services\MediaService;

class OverlayTools
{
    const POSITIONS = ['position-top-left', 'position-top-right', 'position-bottom-left', 'position-bottom-right'];

    const LAYER_TYPES = ['form', 'email', 'text_cta', 'hotspot'];

    const PRO_LAYER_TYPES = ['text_cta', 'hotspot'];

    const MAX_HOTSPOTS = 5;

    public static function definitions()
    {
        $authoring = Helper::authoringCapability();
        $group = __('Overlays & Layers', 'fluent-player');
        $colour = ['type' => 'string', 'pattern' => '^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$', 'description' => 'Hex colour, e.g. #0E121B'];
        $seconds = ['type' => 'number', 'minimum' => 0, 'description' => 'Seconds from the start of the video.'];
        $mediaId = ['type' => 'integer', 'minimum' => 1];

        return [
            'fluent-player/upsert-overlay' => [
                'label'            => __('Add or Edit Overlay', 'fluent-player'),
                'description'      => __('Add a timed text/CTA overlay (e.g. a "Buy now" link at 2:10) or edit one by overlay_id. Only the fields you send change. Overlays show on the player with Fluent Player Pro.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['media_id'],
                    'properties' => array_merge([
                        'media_id'         => $mediaId,
                        'overlay_id'       => ['type' => 'string', 'description' => 'Omit to add a new overlay.'],
                        'text'             => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                        'link'             => ['type' => 'string', 'format' => 'uri'],
                        'display_time'     => $seconds,
                        'duration'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3600, 'description' => 'Seconds the overlay stays visible.'],
                        'position'         => ['type' => 'string', 'enum' => self::POSITIONS],
                        'background_color' => $colour,
                        'text_color'       => $colour,
                    ], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'upsertOverlay'],
            ],
            'fluent-player/delete-overlay' => [
                'label'            => __('Delete Overlay', 'fluent-player'),
                'description'      => __('Remove one overlay from a media item.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false, 'destructive' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['media_id', 'overlay_id'],
                    'properties' => array_merge(['media_id' => $mediaId, 'overlay_id' => ['type' => 'string']], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'deleteOverlay'],
            ],
            'fluent-player/upsert-layer' => [
                'label'            => __('Add or Edit Layer', 'fluent-player'),
                'description'      => __('Add a layer that pauses the video at display_time, or edit one by layer_id. Types: email (email capture; leads are stored in Fluent Player), form (a Fluent Forms form), text_cta (Pro, HTML call-to-action), hotspot (Pro, clickable points). Email provider settings are never read or changed here.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['media_id', 'type'],
                    'properties' => array_merge([
                        'media_id'              => $mediaId,
                        'layer_id'              => ['type' => 'string', 'description' => 'Omit to add a new layer.'],
                        'type'                  => ['type' => 'string', 'enum' => self::LAYER_TYPES],
                        'display_time'          => array_merge($seconds, ['exclusiveMinimum' => 0]),
                        'allow_skip'            => ['type' => 'boolean'],
                        'title'                 => ['type' => 'string', 'maxLength' => 200],
                        'form_id'               => ['type' => 'integer', 'minimum' => 1, 'description' => 'form: Fluent Forms form ID.'],
                        'headline'              => ['type' => 'string', 'maxLength' => 300, 'description' => 'email'],
                        'button_text'           => ['type' => 'string', 'maxLength' => 100, 'description' => 'email'],
                        'placeholder'           => ['type' => 'string', 'maxLength' => 100, 'description' => 'email'],
                        'bottom_text'           => ['type' => 'string', 'maxLength' => 300, 'description' => 'email'],
                        'confirmation_message'  => ['type' => 'string', 'maxLength' => 300, 'description' => 'email'],
                        'content'               => ['type' => 'string', 'maxLength' => 5000, 'description' => 'text_cta: HTML.'],
                        'completion_type'       => ['type' => 'string', 'enum' => ['link_click', 'auto_dismiss', 'skip_only'], 'description' => 'text_cta'],
                        'auto_dismiss_duration' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600, 'description' => 'text_cta, seconds'],
                        'duration'              => ['type' => 'number', 'minimum' => 1, 'maximum' => 3600, 'description' => 'hotspot: seconds visible'],
                        'hotspots'              => [
                            'type'        => 'array',
                            'maxItems'    => self::MAX_HOTSPOTS,
                            'description' => 'hotspot: replaces all points.',
                            'items'       => [
                                'type'                 => 'object',
                                'required'             => ['tooltip_text', 'position_x', 'position_y'],
                                'additionalProperties' => false,
                                'properties'           => [
                                    'tooltip_text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                                    'link'         => ['type' => 'string', 'format' => 'uri'],
                                    'position_x'   => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => '% from left'],
                                    'position_y'   => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => '% from top'],
                                ],
                            ],
                        ],
                    ], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'upsertLayer'],
            ],
            'fluent-player/delete-layer' => [
                'label'            => __('Delete Layer', 'fluent-player'),
                'description'      => __('Remove one layer. The preview shows how many collected leads are linked to it (the leads themselves are kept).', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false, 'destructive' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['media_id', 'layer_id'],
                    'properties' => array_merge(['media_id' => $mediaId, 'layer_id' => ['type' => 'string']], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'deleteLayer'],
            ],
        ];
    }

    public static function upsertOverlay($params)
    {
        $media = MediaTools::findEditableMedia($params);
        if (is_wp_error($media)) {
            return $media;
        }

        $settings = is_array($media->settings) ? $media->settings : [];
        $overlays = array_values((array) (isset($settings['overlays']) ? $settings['overlays'] : []));
        $index = self::findIndex($overlays, isset($params['overlay_id']) ? $params['overlay_id'] : null);

        if (!empty($params['overlay_id']) && null === $index) {
            return MCPHelper::error(ErrorCodes::NOT_FOUND, __('Overlay not found on this media.', 'fluent-player'), ['overlay_id' => $params['overlay_id']]);
        }

        $patch = self::pick($params, [
            'text'             => 'text',
            'link'             => 'link',
            'display_time'     => 'displayTime',
            'duration'         => 'duration',
            'position'         => 'position',
            'background_color' => 'backgroundColor',
            'text_color'       => 'textColor',
        ]);

        if (null === $index) {
            if (!isset($patch['text'], $patch['displayTime'])) {
                return MCPHelper::error(ErrorCodes::MISSING_PARAM, __('A new overlay needs text and display_time.', 'fluent-player'));
            }
            $overlay = array_merge([
                'id'              => MediaTools::newItemId(),
                'position'        => 'position-top-left',
                'backgroundColor' => '#0E121B',
                'textColor'       => '#FFFFFF',
                'duration'        => 5,
            ], $patch);
            $before = null;
        } else {
            $before = $overlays[$index];
            $overlay = array_merge($before, $patch);
        }

        $overlay['displayTime'] = (float) $overlay['displayTime'];
        $overlay['duration'] = (int) $overlay['duration'];
        if (null === $index) {
            $overlays[] = $overlay;
        } else {
            $overlays[$index] = $overlay;
        }
        $settings['overlays'] = $overlays;

        return MediaTools::writeSettingsGuarded(
            'fluent-player/upsert-overlay',
            $params,
            $media,
            $settings,
            ['action' => null === $before ? 'add' : 'edit', 'from' => $before, 'to' => $overlay, 'note' => Helper::hasPro() ? '' : __('Overlays are saved but only show on the player with Fluent Player Pro.', 'fluent-player')],
            null === $before ? __('Overlay added.', 'fluent-player') : __('Overlay updated.', 'fluent-player'),
            ['overlay' => $overlay]
        );
    }

    public static function deleteOverlay($params)
    {
        $media = MediaTools::findEditableMedia($params);
        if (is_wp_error($media)) {
            return $media;
        }

        $settings = is_array($media->settings) ? $media->settings : [];
        $overlays = array_values((array) (isset($settings['overlays']) ? $settings['overlays'] : []));
        $index = self::findIndex($overlays, $params['overlay_id']);
        if (null === $index) {
            return MCPHelper::error(ErrorCodes::NOT_FOUND, __('Overlay not found on this media.', 'fluent-player'), ['overlay_id' => $params['overlay_id']]);
        }

        $removed = $overlays[$index];
        array_splice($overlays, $index, 1);
        $settings['overlays'] = $overlays;

        return MediaTools::writeSettingsGuarded(
            'fluent-player/delete-overlay',
            $params,
            $media,
            $settings,
            ['remove' => $removed],
            __('Overlay deleted.', 'fluent-player'),
            ['overlay_id' => (string) $removed['id']]
        );
    }

    public static function upsertLayer($params)
    {
        $media = MediaTools::findEditableMedia($params);
        if (is_wp_error($media)) {
            return $media;
        }

        $type = $params['type'];
        if (in_array($type, self::PRO_LAYER_TYPES, true) && !Helper::hasPro()) {
            return MCPHelper::error(ErrorCodes::FORBIDDEN, __('This layer type needs Fluent Player Pro.', 'fluent-player'), ['type' => $type]);
        }

        $settings = is_array($media->settings) ? $media->settings : [];
        $layers = array_values((array) (isset($settings['layers']) ? $settings['layers'] : []));
        $index = self::findIndex($layers, isset($params['layer_id']) ? $params['layer_id'] : null);

        if (!empty($params['layer_id']) && null === $index) {
            return MCPHelper::error(ErrorCodes::NOT_FOUND, __('Layer not found on this media.', 'fluent-player'), ['layer_id' => $params['layer_id']]);
        }
        if (null !== $index && self::layerKind($layers[$index]) !== $type) {
            return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('A layer cannot change type. Delete it and add a new one.', 'fluent-player'), ['current_type' => self::layerKind($layers[$index])]);
        }

        $displayTime = isset($params['display_time']) ? (float) $params['display_time'] : (null !== $index ? (float) $layers[$index]['displayTime'] : null);
        if (null === $displayTime || $displayTime <= 0) {
            return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('display_time must be greater than 0.', 'fluent-player'), ['field' => 'display_time']);
        }
        foreach ($layers as $i => $other) {
            if ($i !== $index && isset($other['displayTime']) && (float) $other['displayTime'] === $displayTime) {
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('Another layer already shows at this time. Pick a different display_time.', 'fluent-player'), ['field' => 'display_time']);
            }
        }

        $before = null === $index ? null : $layers[$index];
        $layer = self::buildLayer($type, $before, $params, $displayTime);
        if (is_wp_error($layer)) {
            return $layer;
        }

        if (null === $index) {
            $layers[] = $layer;
        } else {
            $layers[$index] = $layer;
        }
        $settings['layers'] = $layers;

        return MediaTools::writeSettingsGuarded(
            'fluent-player/upsert-layer',
            $params,
            $media,
            $settings,
            ['action' => null === $before ? 'add' : 'edit', 'from' => self::safeLayer($before), 'to' => self::safeLayer($layer)],
            null === $before ? __('Layer added.', 'fluent-player') : __('Layer updated.', 'fluent-player'),
            ['layer' => self::safeLayer($layer)]
        );
    }

    public static function deleteLayer($params)
    {
        $media = MediaTools::findEditableMedia($params);
        if (is_wp_error($media)) {
            return $media;
        }

        $settings = is_array($media->settings) ? $media->settings : [];
        $layers = array_values((array) (isset($settings['layers']) ? $settings['layers'] : []));
        $index = self::findIndex($layers, $params['layer_id']);
        if (null === $index) {
            return MCPHelper::error(ErrorCodes::NOT_FOUND, __('Layer not found on this media.', 'fluent-player'), ['layer_id' => $params['layer_id']]);
        }

        $removed = $layers[$index];
        array_splice($layers, $index, 1);
        $settings['layers'] = $layers;
        $linkedLeads = EmailCollection::query()
            ->where('media_id', (int) $media->ID)
            ->where('layer_id', (int) $removed['id'])
            ->count();

        return MediaTools::writeSettingsGuarded(
            'fluent-player/delete-layer',
            $params,
            $media,
            $settings,
            ['remove' => self::safeLayer($removed), 'linked_leads' => $linkedLeads, 'note' => __('Collected leads are kept.', 'fluent-player')],
            __('Layer deleted.', 'fluent-player'),
            ['layer_id' => (string) $removed['id']]
        );
    }

    /** @return array|\WP_Error */
    private static function buildLayer($type, $before, array $params, $displayTime)
    {
        $layer = $before ?: ['id' => MediaTools::newItemId()];
        $layer['displayTime'] = $displayTime;
        if (isset($params['allow_skip'])) {
            $layer['allow_skip'] = (bool) $params['allow_skip'];
        }
        if (isset($params['title'])) {
            $layer['title'] = sanitize_text_field($params['title']);
        }

        switch ($type) {
            case 'form':
                if (!$before && empty($params['form_id'])) {
                    return MCPHelper::error(ErrorCodes::MISSING_PARAM, __('A form layer needs form_id.', 'fluent-player'), ['field' => 'form_id']);
                }
                $layer = array_merge(['type' => 'form', 'form_type' => 'fluentforms', 'allow_skip' => true, 'width' => 90, 'height' => 90, 'position' => 'center'], $layer);
                if (!empty($params['form_id'])) {
                    $layer['form_id'] = (int) $params['form_id'];
                }
                return $layer;

            case 'email':
                if (!$before && (empty($params['headline']) || empty($params['button_text']))) {
                    return MCPHelper::error(ErrorCodes::MISSING_PARAM, __('A new email layer needs a headline and button_text.', 'fluent-player'), ['field' => 'headline']);
                }
                $capture = isset($layer['email_capture']) && is_array($layer['email_capture'])
                    ? $layer['email_capture']
                    : ['enabled' => true, 'providers' => []];
                foreach (['headline', 'button_text', 'placeholder', 'bottom_text', 'confirmation_message'] as $field) {
                    if (isset($params[$field])) {
                        $capture[$field] = sanitize_text_field($params[$field]);
                    }
                }
                if (empty($capture['placeholder'])) {
                    $capture['placeholder'] = __('Enter your email', 'fluent-player');
                }
                $capture['enabled'] = true;
                $layer = array_merge(['type' => 'cta', 'cta_type' => 'email', 'allow_skip' => false], $layer);
                $layer['email_capture'] = $capture;
                return $layer;

            case 'text_cta':
                if (!$before && empty($params['content'])) {
                    return MCPHelper::error(ErrorCodes::MISSING_PARAM, __('A text_cta layer needs content.', 'fluent-player'), ['field' => 'content']);
                }
                $layer = array_merge(['type' => 'cta', 'cta_type' => 'text', 'allow_skip' => true, 'completion_type' => 'link_click', 'auto_dismiss_duration' => 5], $layer);
                if (isset($params['content'])) {
                    $layer['content'] = wp_kses_post($params['content']);
                }
                foreach (['completion_type', 'auto_dismiss_duration'] as $field) {
                    if (isset($params[$field])) {
                        $layer[$field] = $params[$field];
                    }
                }
                return $layer;

            case 'hotspot':
                if (!$before && empty($params['hotspots'])) {
                    return MCPHelper::error(ErrorCodes::MISSING_PARAM, __('A hotspot layer needs at least one hotspot.', 'fluent-player'), ['field' => 'hotspots']);
                }
                $layer = array_merge(['type' => 'hotspot', 'duration' => 5, 'pauseOnHover' => false], $layer);
                if (isset($params['duration'])) {
                    $layer['duration'] = (float) $params['duration'];
                }
                if (isset($params['hotspots'])) {
                    $points = [];
                    foreach ($params['hotspots'] as $i => $point) {
                        $points[] = [
                            'id'           => (int) round(microtime(true) * 1000) + $i,
                            'tooltip_text' => sanitize_text_field($point['tooltip_text']),
                            'link'         => isset($point['link']) ? esc_url_raw($point['link']) : '',
                            'type'         => 'icon',
                            'icon'         => 'info-circle',
                            'position_x'   => (float) $point['position_x'],
                            'position_y'   => (float) $point['position_y'],
                        ];
                    }
                    $layer['hotspots'] = $points;
                }
                return $layer;
        }

        return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('Unknown layer type.', 'fluent-player'), ['field' => 'type']);
    }

    /** The tool-facing type name for a stored layer. */
    private static function layerKind($layer)
    {
        $type = isset($layer['type']) ? $layer['type'] : '';
        if ('cta' === $type) {
            return (isset($layer['cta_type']) && 'text' === $layer['cta_type']) ? 'text_cta' : 'email';
        }

        return $type;
    }

    private static function safeLayer($layer)
    {
        if (!is_array($layer)) {
            return $layer;
        }

        return MediaService::stripSensitiveProviderConfig(['layers' => [$layer]])['layers'][0];
    }

    private static function findIndex(array $items, $id)
    {
        if (null === $id || '' === $id) {
            return null;
        }
        foreach ($items as $i => $item) {
            if (isset($item['id']) && (string) $item['id'] === (string) $id) {
                return $i;
            }
        }

        return null;
    }

    /** Copies the sent tool params into stored-setting keys. */
    private static function pick(array $params, array $map)
    {
        $out = [];
        foreach ($map as $param => $key) {
            if (array_key_exists($param, $params)) {
                $out[$key] = 'text' === $param ? sanitize_text_field($params[$param]) : ('link' === $param ? esc_url_raw($params[$param]) : $params[$param]);
            }
        }

        return $out;
    }
}
