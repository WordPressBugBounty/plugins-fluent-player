<?php

namespace FluentPlayer\App\Modules\MCP\Tools;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Http\Controllers\MediaController;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Modules\MCP\Support\ControllerCall;
use FluentPlayer\App\Modules\MCP\Support\ErrorCodes;
use FluentPlayer\App\Modules\MCP\Support\MCPHelper;
use FluentPlayer\App\Modules\MCP\Support\Mutation;
use FluentPlayer\App\Modules\MCP\Support\WriteGuard;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\App\Services\PresetService;
use FluentPlayer\Framework\Foundation\App;

class MediaTools
{
    const STATUSES = ['publish', 'draft', 'private'];

    const MAX_BULK = 50;

    const MAX_CHAPTER_TITLE = 200;

    const DEDICATED_TOOL_KEYS = [
        'layers'        => 'fluent-player/upsert-layer',
        'email_capture' => 'fluent-player/upsert-layer',
        'overlays'      => 'fluent-player/upsert-overlay',
        'subtitles'     => 'fluent-player/attach-subtitle',
        'chapters'      => 'fluent-player/save-media-chapters',
    ];

    // Hosted-provider objects hold tokens and ids that get-media redacts; a partial copy would wipe the rest.
    const SOURCE_KEYS = ['stream', 'mux', 'bunny', 'bunny_storage', 'r2', 'gumlet', 'attachment_id'];

    // Per-media colours only render when useCustomBranding is on (Helper::enqueuePlayerStyles).
    const BRANDING_KEYS = ['brandingColor', 'controlBarColor', 'playButtonColor', 'playButtonBgColor'];

    public static function definitions()
    {
        $authoring = Helper::authoringCapability();
        $mediaId = ['type' => 'integer', 'minimum' => 1, 'description' => 'Media ID.'];
        $group = __('Media', 'fluent-player');

        return [
            'fluent-player/list-media' => [
                'label'            => __('List Media', 'fluent-player'),
                'description'      => __('List media items (videos and audio) with their shortcode. Filter by status, provider, or search text (a title, or a media ID). duration is null when it is not known yet.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => array_merge([
                        'search'   => ['type' => 'string', 'description' => 'Match against the title, or a media ID.'],
                        'status'   => ['type' => 'string', 'enum' => ['publish', 'draft', 'private', 'trash', 'all'], 'default' => 'all'],
                        'provider' => ['type' => 'string', 'enum' => array_values(MediaService::allowedProviders())],
                    ], MCPHelper::pageSchema()),
                ],
                'execute_callback' => [self::class, 'listMedia'],
            ],
            'fluent-player/get-media' => [
                'label'            => __('Get Media', 'fluent-player'),
                'description'      => __('Full settings of one media item (provider secrets removed), including overlays, layers and chapters with their times, plus the shortcode and block markup to embed it.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['media_id'],
                    'properties' => ['media_id' => $mediaId],
                ],
                'execute_callback' => [self::class, 'getMedia'],
            ],
            'fluent-player/create-media-from-url' => [
                'label'            => __('Create Media From URL', 'fluent-player'),
                'description'      => __('Create a player from a YouTube, Vimeo, Dailymotion or direct media URL. Saved as a draft unless status is "publish". Returns the shortcode and block markup.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['url'],
                    'properties' => array_merge([
                        'url'         => ['type' => 'string', 'format' => 'uri', 'description' => 'YouTube, Vimeo, Dailymotion or direct video/audio URL.'],
                        'title'       => ['type' => 'string', 'maxLength' => 200],
                        'status'      => ['type' => 'string', 'enum' => ['draft', 'publish'], 'default' => 'draft'],
                        'preset_slug' => ['type' => 'string', 'description' => 'Defaults to the site default preset.'],
                    ], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'createMediaFromUrl'],
            ],
            'fluent-player/update-media-settings' => [
                'label'            => __('Update Media Settings', 'fluent-player'),
                'description'      => __('Change some settings of a media item (e.g. title, posterSrc, autoplay, mutedAutoplay, preset_slug). Branding colours: brandingColor, controlBarColor, playButtonColor, playButtonBgColor (hex); sending any of them switches this media to its own branding unless useCustomBranding is false. Only the keys you send change; everything else is kept.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['media_id', 'settings'],
                    'properties' => array_merge([
                        'media_id' => $mediaId,
                        'settings' => ['type' => 'object', 'description' => 'Settings keys to change, as returned by get-media.'],
                        'status'   => ['type' => 'string', 'enum' => self::STATUSES],
                    ], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'updateMediaSettings'],
            ],
            'fluent-player/bulk-update-media-status' => [
                'label'            => __('Bulk Update Media Status', 'fluent-player'),
                'description'      => __('Publish, draft, make private, trash or restore up to 50 media items at once. Trashed media stop playing wherever they are embedded until restored.', 'fluent-player'),
                'group'            => $group,
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['action', 'media_ids'],
                    'properties' => array_merge([
                        'action'    => ['type' => 'string', 'enum' => ['publish', 'draft', 'private', 'trash', 'restore']],
                        'media_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1, 'maxItems' => self::MAX_BULK],
                    ], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'bulkUpdateMediaStatus'],
            ],
            'fluent-player/save-media-chapters' => [
                'label'            => __('Save Media Chapters', 'fluent-player'),
                'description'      => __('Replace all chapters of one media item. Times are in seconds; a chapter without endTime ends where the next one starts.', 'fluent-player'),
                'group'            => __('Chapters', 'fluent-player'),
                'capability'       => $authoring,
                'annotations'      => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['media_id', 'chapters'],
                    'properties' => array_merge([
                        'media_id' => $mediaId,
                        'chapters' => [
                            'type'     => 'array',
                            'maxItems' => 200,
                            'items'    => [
                                'type'                 => 'object',
                                'required'             => ['title', 'startTime'],
                                'additionalProperties' => false,
                                'properties'           => [
                                    // Room for the fence get-media adds, so a title read back can be saved again.
                                    'title'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_CHAPTER_TITLE + strlen(MCPHelper::UNTRUSTED_OPEN . MCPHelper::UNTRUSTED_CLOSE)],
                                    'startTime' => ['type' => 'number', 'minimum' => 0],
                                    'endTime'   => ['type' => 'number', 'minimum' => 0],
                                ],
                            ],
                        ],
                    ], WriteGuard::schemaProps()),
                ],
                'execute_callback' => [self::class, 'saveMediaChapters'],
            ],
        ];
    }

    public static function listMedia($params)
    {
        $paging = MCPHelper::pagination($params);
        $args = [
            'page'     => $paging['page'],
            'per_page' => $paging['per_page'],
            'status'   => isset($params['status']) ? $params['status'] : 'all',
            'query'    => isset($params['search']) ? $params['search'] : '',
        ];
        if (!empty($params['provider'])) {
            $args['provider'] = [$params['provider']];
        }

        $paginator = (new MediaService())->paginate($args);

        $rows = [];
        foreach ($paginator->items() as $item) {
            $settings = is_array($item->settings) ? $item->settings : [];
            $rows[] = [
                'id'        => (int) $item->ID,
                'title'     => MCPHelper::untrusted((string) $item->post_title),
                'status'    => (string) $item->post_status,
                'provider'  => isset($settings['provider']) ? $settings['provider'] : '',
                'view_type' => isset($settings['viewType']) ? $settings['viewType'] : '',
                'preset'    => isset($settings['preset_slug']) ? $settings['preset_slug'] : '',
                'duration'  => !empty($settings['duration']) ? (float) $settings['duration'] : null,
                'shortcode' => self::shortcode($item->ID),
            ];
        }

        return MCPHelper::envelope(
            /* translators: %d: number of media items */
            sprintf(_n('%d media item.', '%d media items.', $paginator->total(), 'fluent-player'), $paginator->total()),
            $rows,
            MCPHelper::pagingMeta($paging['page'], $paging['per_page'], $paginator->total()) + ['content_warning' => MCPHelper::CONTENT_WARNING]
        );
    }

    public static function getMedia($params)
    {
        $media = self::findEditableMedia($params);
        if (is_wp_error($media)) {
            return $media;
        }

        $settings = Helper::redactMediaSettingsForEditor($media->settings);
        if (isset($settings['title'])) {
            $settings['title'] = MCPHelper::untrusted($settings['title']);
        }
        if (isset($settings['chapters']) && is_array($settings['chapters'])) {
            $settings['chapters'] = self::fenceChapterTitles($settings['chapters']);
        }

        return MCPHelper::envelope(self::shortcode($media->ID), [
            'id'       => (int) $media->ID,
            'title'    => MCPHelper::untrusted($media->post_title),
            'status'   => $media->post_status,
            'date'     => $media->post_date,
            'settings' => $settings,
            'embed'    => self::embed($media->ID, $media->post_status),
        ], ['content_warning' => MCPHelper::CONTENT_WARNING]);
    }

    public static function createMediaFromUrl($params)
    {
        $url = esc_url_raw(isset($params['url']) ? $params['url'] : '');
        if (!$url) {
            return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('A valid URL is required.', 'fluent-player'), ['field' => 'url']);
        }

        $settings = self::settingsFromUrl($url, $params);
        if (is_wp_error($settings)) {
            return $settings;
        }

        $tool = 'fluent-player/create-media-from-url';

        return Mutation::runGuarded(
            $tool,
            $params,
            'media-url:' . md5($url),
            md5(wp_json_encode($settings)),
            function () use ($settings) {
                return [
                    'title'     => MCPHelper::untrusted($settings['title']),
                    'src'       => $settings['src'],
                    'provider'  => $settings['provider'],
                    'view_type' => $settings['viewType'],
                    'preset'    => $settings['preset_slug'],
                    'status'    => $settings['post_status'],
                    'is_short'  => !empty($settings['is_short']),
                    'content_warning' => MCPHelper::CONTENT_WARNING,
                ];
            },
            function () use ($settings) {
                $result = ControllerCall::callWithInput(MediaController::class, 'store', ['settings' => $settings]);
                if (is_wp_error($result)) {
                    return self::rejected($result);
                }

                $id = (int) $result['media']['ID'];

                return MCPHelper::envelope(
                    /* translators: %d: media id */
                    sprintf(__('Created media #%d.', 'fluent-player'), $id),
                    ['id' => $id, 'title' => MCPHelper::untrusted($settings['title']), 'embed' => self::embed($id, get_post_status($id))],
                    ['content_warning' => MCPHelper::CONTENT_WARNING]
                );
            },
            function ($result) {
                return is_array($result) && isset($result['data']['id']) ? 'media:' . $result['data']['id'] : 'media:new';
            }
        );
    }

    public static function updateMediaSettings($params)
    {
        $media = self::findEditableMedia($params);
        if (is_wp_error($media)) {
            return $media;
        }

        $current = is_array($media->settings) ? $media->settings : [];
        $patch = isset($params['settings']) && is_array($params['settings']) ? $params['settings'] : [];
        if (isset($patch['title'])) {
            $patch['title'] = MCPHelper::removeFenceMarkers($patch['title']);
        }
        // get-media strips provider secrets, so a layers round trip would erase them; these keys have dedicated tools.
        if (array_key_exists('subtitles', $patch) && !Helper::hasPro()) {
            return MCPHelper::error(ErrorCodes::FORBIDDEN, __('Captions need Fluent Player Pro.', 'fluent-player'), ['field' => 'settings.subtitles']);
        }
        foreach (self::SOURCE_KEYS as $key) {
            if (array_key_exists($key, $patch)) {
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, sprintf(
                    /* translators: %s: settings key */
                    __('"%s" describes the video source and cannot be changed by AI. Change the source in the Fluent Player admin.', 'fluent-player'),
                    $key
                ), ['field' => 'settings.' . $key]);
            }
        }
        foreach (self::DEDICATED_TOOL_KEYS as $key => $tool) {
            if (array_key_exists($key, $patch)) {
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, sprintf(
                    /* translators: 1: settings key, 2: tool name */
                    __('"%1$s" cannot be changed here. Use %2$s instead.', 'fluent-player'),
                    $key,
                    MCPHelper::mcpToolName($tool)
                ), ['field' => 'settings.' . $key]);
            }
        }
        // get-media copies the live status into settings, so a round trip carries it; any other value
        // would bypass the status enum (the controller also takes 'auto-draft', which core deletes).
        if (array_key_exists('post_status', $patch)) {
            $requested = isset($params['status']) ? $params['status'] : null;
            if (!in_array($patch['post_status'], [get_post_status($media->ID), $requested], true)) {
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('Use the "status" parameter to change the status: publish, draft or private.', 'fluent-player'), ['field' => 'settings.post_status']);
            }
            unset($patch['post_status']);
        }
        if (!empty($params['status'])) {
            $patch['post_status'] = $params['status'];
        }
        if (array_key_exists('preset_slug', $patch)) {
            $preset = PresetService::resolveRef((string) $patch['preset_slug']);
            if (!$preset) {
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('That preset does not exist. Use list-presets to see the slugs.', 'fluent-player'), ['field' => 'settings.preset_slug']);
            }
            if ($proPreset = PresetTools::refuseProPreset($preset, 'settings.preset_slug')) {
                return $proPreset;
            }
            $patch = array_replace(self::applyPresetBehaviors([], $preset), $patch, ['preset_slug' => $preset['slug']]);
        }
        if (array_intersect_key($patch, array_flip(self::BRANDING_KEYS)) && !array_key_exists('useCustomBranding', $patch)) {
            $patch['useCustomBranding'] = true;
        }
        $merged = array_replace($current, $patch);
        $id = (int) $media->ID;

        return Mutation::runGuarded(
            'fluent-player/update-media-settings',
            $params,
            'media:' . $id,
            self::fingerprintReader((int) $media->ID),
            function () use ($current, $patch) {
                // Redact before diffing: the redactor only recognises the settings shape, not {from, to}.
                $safeCurrent = Helper::redactMediaSettingsForEditor($current);
                $changes = [];
                foreach (Helper::redactMediaSettingsForEditor($patch) as $key => $value) {
                    $changes[$key] = [
                        'from' => array_key_exists($key, $safeCurrent) ? $safeCurrent[$key] : null,
                        'to'   => $value,
                    ];
                }

                return ['changes' => $changes];
            },
            function () use ($id, $merged, $patch) {
                $result = self::saveSettings($id, $merged, $patch);
                if (is_wp_error($result)) {
                    return $result;
                }

                /* translators: %d: media id */
                return MCPHelper::envelope(sprintf(__('Updated media #%d.', 'fluent-player'), $id), ['id' => $id, 'embed' => self::embed($id, get_post_status($id))], self::editorNote());
            },
            'media:' . $id
        );
    }

    public static function bulkUpdateMediaStatus($params)
    {
        $ids = array_values(array_unique(array_map('absint', (array) $params['media_ids'])));
        sort($ids);
        $action = $params['action'];
        $input = in_array($action, self::STATUSES, true)
            ? ['action' => 'change_status', 'status' => $action, 'media_ids' => $ids]
            : ['action' => $action, 'media_ids' => $ids];

        $readStatuses = function () use ($ids) {
            $current = [];
            foreach ($ids as $id) {
                clean_post_cache($id);
                $current[$id] = get_post_status($id) ?: 'missing';
            }

            return $current;
        };
        $current = $readStatuses();

        return Mutation::runGuarded(
            'fluent-player/bulk-update-media-status',
            $params,
            'media-bulk:' . md5(implode(',', $ids)),
            function () use ($action, $readStatuses) {
                return md5($action . wp_json_encode($readStatuses()));
            },
            function () use ($current, $action) {
                $preview = ['action' => $action, 'current_status' => $current];
                if ('trash' === $action) {
                    $preview['effect'] = __('Embeds of these media stop playing until they are restored.', 'fluent-player');
                }

                return $preview;
            },
            function () use ($input) {
                $result = ControllerCall::callWithInput(MediaController::class, 'handleBulkActions', $input);
                if (is_wp_error($result)) {
                    return self::rejected($result);
                }

                return MCPHelper::envelope((string) $result['message'], $result['data']);
            },
            'media-bulk:' . $action
        );
    }

    public static function saveMediaChapters($params)
    {
        $media = self::findEditableMedia($params);
        if (is_wp_error($media)) {
            return $media;
        }

        $chapters = [];
        foreach (array_values((array) $params['chapters']) as $i => $chapter) {
            $row = [
                'title'     => sanitize_text_field(MCPHelper::removeFenceMarkers($chapter['title'])),
                'startTime' => (float) $chapter['startTime'],
            ];
            if ('' === $row['title']) {
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('Each chapter needs a title.', 'fluent-player'), ['chapter' => $i]);
            }
            if (mb_strlen($row['title']) > self::MAX_CHAPTER_TITLE) {
                /* translators: %d: maximum number of characters */
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, sprintf(__('A chapter title can be at most %d characters.', 'fluent-player'), self::MAX_CHAPTER_TITLE), ['chapter' => $i]);
            }
            if (isset($chapter['endTime'])) {
                $row['endTime'] = (float) $chapter['endTime'];
            }
            $chapters[] = $row;
        }
        usort($chapters, function ($a, $b) {
            return $a['startTime'] <=> $b['startTime'];
        });
        foreach ($chapters as $i => $chapter) {
            if ($i > 0 && $chapter['startTime'] == $chapters[$i - 1]['startTime']) {
                return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('Two chapters start at the same time. Give each chapter its own start time.', 'fluent-player'), ['chapter' => $i]);
            }
        }
        // The chapter VTT needs an end after each start; the editor ends a chapter where the next begins.
        $duration = isset($media->settings['duration']) ? (float) $media->settings['duration'] : 0;
        foreach ($chapters as $i => $chapter) {
            $nextStart = isset($chapters[$i + 1]) ? $chapters[$i + 1]['startTime'] : null;
            if (isset($chapter['endTime']) && $chapter['endTime'] > $chapter['startTime']) {
                if (null !== $nextStart && $chapter['endTime'] > $nextStart) {
                    $chapters[$i]['endTime'] = $nextStart;
                }
                continue;
            }
            if (null !== $nextStart) {
                $chapters[$i]['endTime'] = $nextStart;
            } else {
                $chapters[$i]['endTime'] = $duration > $chapter['startTime'] ? $duration : $chapter['startTime'] + 3600;
            }
        }

        $id = (int) $media->ID;
        $current = is_array($media->settings) ? $media->settings : [];
        $merged = array_replace($current, ['chapters' => $chapters]);

        return Mutation::runGuarded(
            'fluent-player/save-media-chapters',
            $params,
            'media:' . $id,
            self::fingerprintReader($id),
            function () use ($current, $chapters) {
                return [
                    'from'            => self::fenceChapterTitles(array_values((array) (isset($current['chapters']) ? $current['chapters'] : []))),
                    'to'              => self::fenceChapterTitles($chapters),
                    'content_warning' => MCPHelper::CONTENT_WARNING,
                ];
            },
            function () use ($id, $merged, $chapters) {
                $result = self::saveSettings($id, $merged);
                if (is_wp_error($result)) {
                    return $result;
                }

                return MCPHelper::envelope(
                    /* translators: 1: chapter count, 2: media id */
                    sprintf(__('Saved %1$d chapters on media #%2$d.', 'fluent-player'), count($chapters), $id),
                    ['media_id' => $id, 'chapters' => self::fenceChapterTitles($chapters)],
                    self::editorNote() + ['content_warning' => MCPHelper::CONTENT_WARNING]
                );
            },
            'media:' . $id
        );
    }

    /**
     * Loads a media item the current user may act on, or a structured error.
     *
     * @return object|\WP_Error
     */
    public static function findEditableMedia($params)
    {
        $id = isset($params['media_id']) ? absint($params['media_id']) : 0;
        $media = $id ? Media::find($id) : null;

        if (!$media) {
            return MCPHelper::error(ErrorCodes::NOT_FOUND, __('Media not found.', 'fluent-player'), ['media_id' => $id]);
        }

        if (!current_user_can('edit_post', $id)) {
            return MCPHelper::error(ErrorCodes::FORBIDDEN, __('You cannot change this media item.', 'fluent-player'), ['media_id' => $id]);
        }

        return $media;
    }

    /**
     * Saves a full settings array for one media item behind WriteGuard (dry_run → confirm).
     *
     * @param array $preview What the dry run shows.
     * @param array $data    Extra fields returned after the write.
     * @return array|\WP_Error
     */
    public static function writeSettingsGuarded($tool, array $params, $media, array $settings, array $preview, $summary, array $data = [])
    {
        $id = (int) $media->ID;

        return Mutation::runGuarded(
            $tool,
            $params,
            'media:' . $id,
            self::fingerprintReader((int) $media->ID),
            function () use ($preview) {
                return $preview;
            },
            function () use ($id, $settings, $summary, $data) {
                $result = self::saveSettings($id, $settings);
                if (is_wp_error($result)) {
                    return $result;
                }

                return MCPHelper::envelope($summary, array_merge(['media_id' => $id], $data), self::editorNote());
            },
            'media:' . $id
        );
    }

    private static function fenceChapterTitles(array $chapters)
    {
        return array_map(function ($chapter) {
            if (is_array($chapter) && isset($chapter['title'])) {
                $chapter['title'] = MCPHelper::untrusted($chapter['title']);
            }
            return $chapter;
        }, $chapters);
    }

    /** The block editor saves its whole loaded copy of the settings, so an open tab would undo this write. */
    public static function editorNote()
    {
        return ['note' => __('If this media is open in the block editor, reload it before saving there, or that save will undo this change.', 'fluent-player')];
    }

    /** New item ids follow the editor's Date.now() format. */
    public static function newItemId()
    {
        return (string) round(microtime(true) * 1000);
    }

    /**
     * Saves a media item's full settings through MediaController::update.
     * WordPress owns the title and status: stored copies can be stale (renamed posts) or
     * rejected ('future' for scheduled media), so only values the caller changed are sent.
     *
     * @return array|\WP_Error
     */
    public static function saveSettings($id, array $settings, array $patch = [])
    {
        if (!array_key_exists('title', $patch)) {
            $settings['title'] = get_post_field('post_title', $id);
        }
        if (!array_key_exists('post_status', $patch)) {
            unset($settings['post_status']);
        }

        $result = ControllerCall::callWithInput(MediaController::class, 'update', ['settings' => $settings], [$id]);

        return is_wp_error($result) ? self::rejected($result) : $result;
    }

    /**
     * State of a media item read straight from the database, so a write that landed in
     * another request since this one started is noticed.
     */
    /** A fingerprint reader for Mutation::runGuarded, evaluated again after the write lease is taken. */
    public static function fingerprintReader($id)
    {
        return function () use ($id) {
            return self::fingerprint((object) ['ID' => (int) $id]);
        };
    }

    public static function fingerprint($media)
    {
        global $wpdb;
        $id = (int) $media->ID;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.post_status, p.post_title, m.meta_value FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'settings'
             WHERE p.ID = %d",
            $id
        ), ARRAY_A);

        return md5(wp_json_encode($row));
    }

    /** Copies a preset's playback behaviours onto media settings, as the block editor's PresetSelector does. */
    public static function applyPresetBehaviors(array $settings, array $preset)
    {
        $behaviors = isset($preset['settings']['behaviors']) ? (array) $preset['settings']['behaviors'] : [];
        $map = [
            'autoplay'             => 'autoplay',
            'muted_autoplay'       => 'mutedAutoplay',
            'plays_inline'         => 'playsInline',
            'hide_top_controls'    => 'hide_top_controls',
            'hide_center_controls' => 'hide_center_controls',
            'hide_bottom_controls' => 'hide_bottom_controls',
        ];
        foreach ($map as $behavior => $key) {
            if (array_key_exists($behavior, $behaviors)) {
                $settings[$key] = (bool) $behaviors[$behavior];
            }
        }
        if (array_key_exists('on_video_end', $behaviors)) {
            $settings['video_end_option'] = 'loop' === $behaviors['on_video_end'] ? 'loop' : 'default';
        }
        $settings['preset_slug'] = $preset['slug'];

        return $settings;
    }

    public static function shortcode($id)
    {
        return '[fluentplayer id="' . (int) $id . '"]';
    }

    public static function embed($id, $status)
    {
        $isLive = 'publish' === $status;

        return [
            'shortcode'  => self::shortcode($id),
            'block'      => '<!-- wp:fluent-player/media {"mediaId":' . (int) $id . '} /-->',
            'status'     => $status,
            'is_live'    => $isLive,
            'note'       => $isLive ? '' : __('Not published: visitors see a login curtain until it is published.', 'fluent-player'),
            'review_url' => admin_url('admin.php?page=' . App::make('config')->get('app.slug') . '#/'),
        ];
    }

    /** The media settings the admin's paste-a-URL flow would create, saved as a draft by default. */
    private static function settingsFromUrl($url, $params)
    {
        $metadata = ControllerCall::callWithInput(MediaController::class, 'getMetadata', ['url' => $url]);
        if (is_wp_error($metadata)) {
            return self::rejected($metadata);
        }

        $meta = isset($metadata['metaData']) ? (array) $metadata['metaData'] : [];
        $providerName = isset($meta['provider_name']) ? $meta['provider_name'] : '';
        if (!empty($meta['provider'])) {
            $provider = $meta['provider'];
        } elseif ('YouTube' === $providerName) {
            $provider = 'youtube';
        } elseif ('Vimeo' === $providerName) {
            $provider = 'vimeo';
        } else {
            $provider = 'external';
        }

        $type = isset($meta['media_type']) ? $meta['media_type'] : (isset($meta['type']) ? $meta['type'] : '');
        $isAudio = 'audio' === $type;
        $title = !empty($params['title']) ? sanitize_text_field(MCPHelper::removeFenceMarkers($params['title'])) : (!empty($meta['title']) ? $meta['title'] : __('Untitled media', 'fluent-player'));

        $presetRef = !empty($params['preset_slug']) ? (string) $params['preset_slug'] : (PresetService::getDefaultSlug() ?: 'course');
        $preset = PresetService::resolveRef($presetRef);
        if (!$preset) {
            return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('That preset does not exist. Use list-presets to see the slugs.', 'fluent-player'), ['field' => 'preset_slug']);
        }
        if ($proPreset = PresetTools::refuseProPreset($preset, 'preset_slug')) {
            return $proPreset;
        }

        $settings = [
            'title'         => $title,
            'post_status'   => (isset($params['status']) && 'publish' === $params['status']) ? 'publish' : 'draft',
            'src'           => !empty($meta['url']) ? $meta['url'] : $url,
            'provider'      => $provider,
            'posterSrc'     => isset($meta['thumbnail_url']) ? $meta['thumbnail_url'] : '',
            'viewType'      => $isAudio ? 'audio' : 'video',
            'playsInline'   => true,
            'mutedAutoplay' => false,
            'autoplay'      => false,
            'loadStrategy'  => 'visible',
            'preload'       => 'metadata',
            'duration'      => isset($meta['duration']) ? $meta['duration'] : 0,
        ];

        if ($isAudio) {
            $settings['mediaType'] = 'audio';
        }
        if (preg_match('#youtube\.com/shorts/#i', $url)) {
            $settings['is_short'] = true;
            $settings['aspectRatio'] = '9:16';
        }
        $settings = self::applyPresetBehaviors($settings, $preset);
        if (!empty($meta['attachment_id'])) {
            $settings['attachment_id'] = (int) $meta['attachment_id'];
        }

        return $settings;
    }

    public static function rejected(\WP_Error $error)
    {
        $data = $error->get_error_data();

        return MCPHelper::error(ErrorCodes::INVALID_PARAM, $error->get_error_message(), [
            'status' => isset($data['status']) ? $data['status'] : 422,
            'errors' => isset($data['errors']) ? $data['errors'] : [],
        ]);
    }
}
