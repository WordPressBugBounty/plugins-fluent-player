<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Models\Post;
use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Helpers\BulkActionHelper;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Support\Collection;
use FluentPlayer\Framework\Support\Arr;

class MediaService
{
    protected const ALLOWED_PROVIDERS = ['wordpress', 'youtube', 'vimeo', 'bunny', 'bunny_storage', 'mux', 'mux_stream'];

    protected const ALLOWED_MEDIA_TYPES = ['audio', 'video'];

    protected const MAX_LANGUAGE_MEDIA_SOURCES = 20;

    protected const ORDER_BY_MAP = [
        'id'            => 'ID',
        'title'         => 'post_title',
        'post_title'    => 'post_title',
        'date'          => 'post_date',
        'post_date'     => 'post_date',
        'modified'      => 'post_modified',
        'post_modified' => 'post_modified',
        'post_status'   => 'post_status',
        'post_name'     => 'post_name',
    ];

    protected function getSerializedMetaFieldMatchParts($field, $value)
    {
        $field = (string) $field;
        $value = (string) $value;

        $serializedPattern = sprintf(
            's:%d:"%s";s:[0-9]+:"%s";',
            strlen($field),
            preg_quote($field, '/'),
            preg_quote($value, '/')
        );

        return [
            'sql'      => '(pm.meta_value REGEXP ? OR pm.meta_value LIKE ?)',
            'bindings' => [
                $serializedPattern,
                '%"' . $field . '":"' . $value . '"%',
            ],
        ];
    }

    protected function getSerializedMetaFieldPresenceParts($field)
    {
        $field = (string) $field;

        return [
            'sql'      => '(pm.meta_value REGEXP ? OR pm.meta_value LIKE ?)',
            'bindings' => [
                sprintf('s:%d:"%s";', strlen($field), preg_quote($field, '/')),
                '%"' . $field . '":%',
            ],
        ];
    }

    protected function applyProviderFilter($query, $providers)
    {
        $providerMatches = array_map(function ($provider) {
            return $this->getSerializedMetaFieldMatchParts('provider', $provider);
        }, $providers);

        $providerSql = implode(' OR ', array_map(function ($match) {
            return $match['sql'];
        }, $providerMatches));
        $providerBindings = [];
        foreach ($providerMatches as $match) {
            $providerBindings = array_merge($providerBindings, $match['bindings']);
        }

        $query->whereExists(function ($subQuery) use ($providerSql, $providerBindings) {
            $subQuery->selectRaw('1')
                ->from('postmeta as pm')
                ->whereColumn('pm.post_id', '=', 'posts.ID')
                ->where('pm.meta_key', 'settings')
                ->whereRaw('(' . $providerSql . ')', $providerBindings);
        });
    }

    protected function applyMediaTypeFilter($query, $mediaTypes)
    {
        $mediaTypes = array_values(array_unique(array_filter($mediaTypes)));

        if (!$mediaTypes) {
            return;
        }

        if (count($mediaTypes) > 1) {
            sort($mediaTypes);
            if ($mediaTypes === ['audio', 'video']) {
                return;
            }

            return;
        }

        $mediaType = $mediaTypes[0];
        $mediaTypeMatch = $this->getSerializedMetaFieldMatchParts('mediaType', $mediaType);
        $mediaTypePresence = $this->getSerializedMetaFieldPresenceParts('mediaType');

        if ($mediaType === 'audio') {
            $viewTypeMatch = $this->getSerializedMetaFieldMatchParts('viewType', 'audio');
            $sql = sprintf(
                '(%1$s OR (NOT %2$s AND %3$s))',
                $mediaTypeMatch['sql'],
                $mediaTypePresence['sql'],
                $viewTypeMatch['sql']
            );
            $bindings = array_merge(
                $mediaTypeMatch['bindings'],
                $mediaTypePresence['bindings'],
                $viewTypeMatch['bindings']
            );
        } else {
            $viewTypeAudioMatch = $this->getSerializedMetaFieldMatchParts('viewType', 'audio');
            $sql = sprintf(
                '(%1$s OR (NOT %2$s AND NOT %3$s))',
                $mediaTypeMatch['sql'],
                $mediaTypePresence['sql'],
                $viewTypeAudioMatch['sql']
            );
            $bindings = array_merge(
                $mediaTypeMatch['bindings'],
                $mediaTypePresence['bindings'],
                $viewTypeAudioMatch['bindings']
            );
        }

        $query->whereExists(function ($subQuery) use ($sql, $bindings) {
            $subQuery->selectRaw('1')
                ->from('postmeta as pm')
                ->whereColumn('pm.post_id', '=', 'posts.ID')
                ->where('pm.meta_key', 'settings')
                ->whereRaw($sql, $bindings);
        });
    }

    protected function normalizeListFilter($value, $sanitizeCallback, $allowedValues = null, $splitCommaSeparated = false)
    {
        if ($splitCommaSeparated && is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            $value = $value ? [$value] : [];
        }

        $value = array_values(array_filter(array_map($sanitizeCallback, $value)));

        if (is_array($allowedValues)) {
            $value = array_values(array_intersect($value, $allowedValues));
        }

        return $value;
    }

    protected function normalizeProviderFilters($provider)
    {
        return $this->normalizeListFilter($provider, 'sanitize_key', self::ALLOWED_PROVIDERS);
    }

    protected function normalizeMediaTypeFilters($mediaType)
    {
        return $this->normalizeListFilter($mediaType, 'sanitize_key', self::ALLOWED_MEDIA_TYPES);
    }

    protected function normalizeTagFilters($tags)
    {
        return $this->normalizeListFilter($tags, 'sanitize_title', null, true);
    }

    protected function normalizePaginationArgs($args)
    {
        $orderByArg = isset($args['orderby']) ? sanitize_text_field($args['orderby']) : 'ID';
        $order = isset($args['order']) ? strtoupper($args['order']) : 'DESC';

        return [
            'per_page'   => max(1, min(100, isset($args['per_page']) ? (int) $args['per_page'] : 10)),
            'status'     => isset($args['status']) ? sanitize_text_field($args['status']) : 'publish',
            'order_by'   => self::ORDER_BY_MAP[$orderByArg] ?? 'ID',
            'order'      => in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC',
            'query'      => isset($args['query']) ? sanitize_text_field($args['query']) : '',
            'provider'   => $this->normalizeProviderFilters(Arr::get($args, 'provider', [])),
            'media_type' => $this->normalizeMediaTypeFilters(Arr::get($args, 'media_type', [])),
            'tags'       => $this->normalizeTagFilters(Arr::get($args, 'tags', [])),
        ];
    }

    protected function baseMediaQuery()
    {
        return Post::select(['post_title', 'ID', 'post_status', 'post_date', 'post_name'])
            ->where('post_type', Media::$postType)
            ->where('post_status', '!=', 'auto-draft');
    }

    protected function applyPaginateFilters($query, $filters)
    {
        $this->applyStatusFilter($query, $filters['status']);
        $this->applySearchFilter($query, $filters['query']);

        if (!empty($filters['provider'])) {
            $this->applyProviderFilter($query, $filters['provider']);
        }

        if (!empty($filters['media_type'])) {
            $this->applyMediaTypeFilter($query, $filters['media_type']);
        }

        $this->applyTagsFilter($query, $filters['tags']);
    }

    protected function applyStatusFilter($query, $status)
    {
        if ($status === 'trash') {
            $query->where('post_status', 'trash');
        } elseif ($status && $status !== 'all') {
            $query->where('post_status', $status);
        } else {
            $query->where('post_status', '!=', 'trash');
        }
    }

    protected function applySearchFilter($query, $searchQuery)
    {
        if (!$searchQuery) {
            return;
        }

        global $wpdb;
        $escapedQuery = $wpdb->esc_like($searchQuery);
        $query->where(function ($q) use ($escapedQuery) {
            $q->where('post_title', 'LIKE', '%' . $escapedQuery . '%')
                ->orWhere('ID', 'LIKE', '%' . $escapedQuery . '%')
                ->orWhere('post_status', 'LIKE', '%' . $escapedQuery . '%')
                ->orWhere('post_name', 'LIKE', '%' . $escapedQuery . '%');
        });
    }

    protected function applyTagsFilter($query, $tags)
    {
        if (empty($tags)) {
            return;
        }

        if (!taxonomy_exists('flp_media_tag')) {
            $query->where('ID', 0);
            return;
        }

        $terms = get_terms([
            'taxonomy'   => 'flp_media_tag',
            'hide_empty' => false,
            'slug'       => $tags,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            $query->where('ID', 0);
            return;
        }

        $termIds = array_map(function ($term) {
            return (int) $term->term_taxonomy_id;
        }, $terms);

        $query->whereExists(function ($subQuery) use ($termIds) {
            $subQuery->selectRaw('1')
                ->from('term_relationships')
                ->whereColumn('term_relationships.object_id', '=', 'posts.ID')
                ->whereIn('term_relationships.term_taxonomy_id', $termIds);
        });
    }

    protected function applySorting($query, $orderBy, $order)
    {
        if ($orderBy && $order) {
            $query->orderBy($orderBy, $order);
            if (in_array($orderBy, ['post_date', 'post_modified', 'post_title'], true)) {
                $query->orderBy('ID', $order);
            }
            return;
        }

        $query->latest('ID');
    }

    protected function attachSettingsAndTags($paginator)
    {
        $collection = $paginator->getCollection();

        // Batch load media settings for all items
        Media::batchLoadMediaSettings($collection->all());

        $allIds = array_map(fn($item) => $item->ID, $collection->all());

        // Tags are a pro feature: prime the post term cache once, then read tags from the cached path.
        $tagsEnabled = taxonomy_exists('flp_media_tag');
        if ($tagsEnabled && $allIds) {
            update_object_term_cache($allIds, Media::$postType);
        }

        $mediasArray = [];
        foreach ($collection as $item) {
            $item->settings = Media::getMediaSettings($item);
            if ($tagsEnabled) {
                $tags = get_the_terms($item->ID, 'flp_media_tag');
                $item->tags = is_wp_error($tags) || empty($tags) ? [] : array_values(wp_list_pluck($tags, 'name'));
            } else {
                $item->tags = [];
            }
            $mediasArray[] = $item;
        }

        $paginator->setCollection(new Collection($mediasArray));
    }

    /**
     * Get paginated media items
     *
     * @param array $args Pagination and filtering arguments
     *
     * @return object Paginator instance with media items
     */
    public function paginate($args = [], $with_settings = true)
    {
        $filters = $this->normalizePaginationArgs($args);
        $query = $this->baseMediaQuery();

        $this->applyPaginateFilters($query, $filters);

        if (!empty($filters['tags'])) {
            $args['tags_query_applied'] = true;
        }

        $query = apply_filters('fluent_player/media_paginate_query', $query, $args);

        $this->applySorting($query, $filters['order_by'], $filters['order']);

        $paginator = $query->paginate($filters['per_page']);
        if ($with_settings) {
            $this->attachSettingsAndTags($paginator);
        }
        return $paginator;
    }
    
    // Statuses bulk change-status may set; trash & scheduled have their own flows.
    public const BULK_STATUSES = ['publish', 'private', 'draft'];

    /**
     * Validate and run a bulk action over media. Free actions (trash, restore,
     * delete, change status) run here; Pro actions (tags, playlist) dispatch
     * through the fluent_player/media_bulk_action filter.
     *
     * @return array|\WP_Error Success payload (message + counts), or a WP_Error carrying an HTTP status.
     */
    public function manageBulkActions(Request $request)
    {
        $action = sanitize_text_field((string) $request->get('action'));
        $ids    = BulkActionHelper::sanitizeIds($request->get('media_ids'));

        if (empty($ids)) {
            return new \WP_Error('fp_bulk_empty', __('No items were selected', 'fluent-player'), ['status' => 422]);
        }

        if (count($ids) > BulkActionHelper::MAX) {
            return new \WP_Error(
                'fp_bulk_too_many',
                // translators: %d is the maximum number of items per bulk request.
                sprintf(__('You can process up to %d items at once', 'fluent-player'), BulkActionHelper::MAX),
                ['status' => 422]
            );
        }

        switch ($action) {
            case 'trash':
                return BulkActionHelper::format($this->bulkTrash($ids));
            case 'restore':
                return BulkActionHelper::format($this->bulkRestore($ids));
            case 'delete_permanently':
                return BulkActionHelper::format($this->bulkForceDelete($ids));
            case 'change_status':
                $status = sanitize_text_field((string) $request->get('status'));
                $result = $this->bulkChangeStatus($ids, $status);
                if ($result === null) {
                    return new \WP_Error('fp_bulk_invalid_status', __('Invalid status', 'fluent-player'), ['status' => 422]);
                }
                return BulkActionHelper::format($result);
        }

        if (in_array($action, ['add_tags', 'remove_tags', 'add_to_playlist'], true)) {
            $handled = apply_filters('fluent_player/media_bulk_action', null, $action, $ids, $request);
            if (is_array($handled) && isset($handled['affected_ids'])) {
                return BulkActionHelper::format($handled, $handled['message'] ?? '');
            }
            return new \WP_Error('fp_bulk_pro_only', __('This is a Pro feature', 'fluent-player'), ['status' => 403]);
        }

        return new \WP_Error('fp_bulk_invalid_action', __('Invalid bulk action', 'fluent-player'), ['status' => 422]);
    }

    /**
     * Move many media items to trash; already-trashed rows are skipped.
     *
     * @param int[] $ids
     * @return array{affected_ids: int[], failed_ids: int[]}
     */
    public function bulkTrash(array $ids)
    {
        return BulkActionHelper::run($ids, function ($id) {
            if (get_post_type($id) !== Media::$postType || get_post_status($id) === 'trash') {
                return false;
            }
            return (bool) wp_trash_post($id);
        });
    }

    /**
     * Restore many trashed media items; non-trashed rows are skipped.
     *
     * @param int[] $ids
     * @return array{affected_ids: int[], failed_ids: int[]}
     */
    public function bulkRestore(array $ids)
    {
        return BulkActionHelper::run($ids, function ($id) {
            if (get_post_type($id) !== Media::$postType || get_post_status($id) !== 'trash') {
                return false;
            }
            return (bool) wp_untrash_post($id);
        });
    }

    /**
     * Permanently delete many media items.
     *
     * @param int[] $ids
     * @return array{affected_ids: int[], failed_ids: int[]}
     */
    public function bulkForceDelete(array $ids)
    {
        return BulkActionHelper::run($ids, function ($id) {
            if (get_post_type($id) !== Media::$postType) {
                return false;
            }
            return (bool) wp_delete_post($id, true);
        });
    }

    /**
     * Change status of many media items; trashed rows are skipped. Null if status disallowed.
     *
     * @param int[]  $ids
     * @param string $status
     * @return array{affected_ids: int[], failed_ids: int[]}|null
     */
    public function bulkChangeStatus(array $ids, $status)
    {
        if (!in_array($status, self::BULK_STATUSES, true)) {
            return null;
        }

        return BulkActionHelper::run($ids, function ($id) use ($status) {
            if (get_post_type($id) !== Media::$postType) {
                return false;
            }
            // Skip trashed rows so their trash meta isn't orphaned.
            if (get_post_status($id) === 'trash') {
                return false;
            }
            $result = wp_update_post(['ID' => $id, 'post_status' => $status], true);
            return !is_wp_error($result) && $result > 0;
        });
    }

    /**
     * Prepare complete media data for frontend use
     * Centralized method used by all handlers (shortcode, blocks, AJAX)
     */
    public static function prepareMediaForFrontend($media, $context = 'frontend')
    {
        // Merge preset settings first
        $media->settings = Media::mergePresetSettings($media->settings);

        // Prepare language media sources
        $languageMediaSources = self::prepareLanguageMediaSources($media);

        // Apply iOS Safari settings if needed (after language sources)
        [$isIosSafari, $iosSafariSettings] = self::getIOSSafariSettings($media->settings);
        if ($isIosSafari) {
            $media->settings = array_merge($media->settings, $iosSafariSettings);
        }

        $media->settings = self::parseSmartcodes($media->settings, ['media' => $media]);

        // Build trusted condition state once and resolve any server-known visibility.
        if (
            Helper::hasPro() &&
            class_exists('\FluentPlayerPro\App\Services\ConditionService')
        ) {
            $media->settings = \FluentPlayerPro\App\Services\ConditionService::maybePrepareConditionState($media->settings, $media->ID);
        }

        $defaultSettings = SettingsService::getMediaDefaultSettings($media->settings);

        // Detect user language for auto-switching
        $serverLang = self::detectUserLanguage();

        return [
            'media'                => $media,
            'default_settings'     => $defaultSettings,
            'languageMediaSources' => $languageMediaSources,
            'serverLang'           => $serverLang,
        ];
    }
    
    /**
     * Prepare language media sources for instant switching
     * Centralized method used by all handlers
     */
    public static function prepareLanguageMediaSources($originalMedia)
    {
        $languageMappings = Arr::get($originalMedia->settings, 'language_mappings', []);
        $languageSources = [];
        
        // Add original media source
        $originalLanguage = Arr::get($originalMedia->settings, 'language', 'en_US');
        $languageSources[$originalLanguage] = [
            'mediaId'   => $originalMedia->ID,
            'src'       => Arr::get($originalMedia->settings, 'src', ''),
            'posterSrc' => Arr::get($originalMedia->settings, 'posterSrc', ''),
            'title'     => $originalMedia->post_title
        ];
        
        // Collect a bounded set of media IDs first, then batch-load.
        $mediaIdToLang = [];
        foreach ($languageMappings as $mapping) {
            if (count($mediaIdToLang) >= self::MAX_LANGUAGE_MEDIA_SOURCES) {
                break;
            }

            $mediaId = absint(Arr::get($mapping, 'media_id'));
            $langCode = sanitize_text_field(Arr::get($mapping, 'language'));
            if ($mediaId && $langCode !== '') {
                $mediaIdToLang[$mediaId] = $langCode;
            }
        }

        if (!empty($mediaIdToLang)) {
            $alternativeMedias = Post::whereIn('ID', array_keys($mediaIdToLang))
                ->where('post_type', Media::$postType)
                ->where('post_status', 'publish')
                ->select(['post_title', 'ID', 'post_status'])
                ->get();

            Media::batchLoadMediaSettings($alternativeMedias->all());

            foreach ($alternativeMedias as $alternativeMedia) {
                $alternativeMedia->settings = Media::getMediaSettings($alternativeMedia);
                $langCode = $mediaIdToLang[$alternativeMedia->ID] ?? null;
                if ($langCode) {
                    $alternativeMedia->settings = Media::mergePresetSettings($alternativeMedia->settings);
                    $languageSources[$langCode] = [
                        'mediaId'   => $alternativeMedia->ID,
                        'src'       => Arr::get($alternativeMedia->settings, 'src', ''),
                        'posterSrc' => Arr::get($alternativeMedia->settings, 'posterSrc', ''),
                        'title'     => $alternativeMedia->post_title
                    ];
                }
            }
        }
        
        return $languageSources;
    }
    
    /**
     * Detect user language for auto-switching
     */
    public static function detectUserLanguage()
    {
        $serverLang = self::getBrowserLanguage();
        if ($serverLang) {
            return $serverLang;
        }
        return 'en_US';
    }
    
    /**
     * Get browser language from HTTP headers
     * Centralized method used by all handlers
     */
    public static function getBrowserLanguage()
    {
        if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return null;
        }
        
        $acceptLanguage = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']));
        $languages = explode(',', $acceptLanguage);
        
        if (empty($languages)) {
            return null;
        }
        
        // Get the first (highest priority) language
        $primaryLanguage = trim(explode(';', $languages[0])[0]);
        
        $wordpressFormat = str_replace('-', '_', $primaryLanguage);
        
        return $wordpressFormat;
    }
    
    /**
     * iOS Safari compatibility (playsinline, preload; muted only when autoplay is on).
     *
     * @param  array $settings Current media settings (used to gate `muted` on autoplay intent).
     * @return array{0: bool, 1: array} [$isIosSafari, $iosSafariSettings]
     */
    public static function getIOSSafariSettings($settings = [])
    {
        $userAgent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $isIos = preg_match('/iPad|iPhone|iPod/i', $userAgent) && !strpos($userAgent, 'MSStream');
        $isSafari = preg_match('/^((?!chrome|android).)*safari/i', $userAgent);
        $isIosSafari = $isIos || ($isSafari && preg_match('/Version\/[\d\.]+.*Safari/', $userAgent));

        if (!$isIosSafari) {
            return [false, []];
        }

        $iosSettings = [
            'playsInline' => true,
            'preload'     => 'metadata',
        ];

        $wantsAutoplay = Arr::isTrue($settings, 'autoplay')
            || Arr::isTrue($settings, 'mutedAutoplay');

        if ($wantsAutoplay) {
            $iosSettings['muted'] = true;
        }

        return [true, $iosSettings];
    }

    /**
     * Parse FluentCRM smartcodes in media settings fields.
     * Resolves {{contact.first_name}}, {{crm.business_name}}, etc.
     *
     * @param array|string $settings Media settings array or a single string value
     * @return array|string Settings/string with smartcodes replaced
     */
    public static function parseSmartcodes($settings, $context = [])
    {
        if (is_string($settings)) {
            if (empty($settings) || !self::containsSmartcode($settings)) {
                return $settings;
            }

            // Order matters: player shortcodes first (CRM-independent), then
            // FluentCRM's {{contact.*}}, then strip whatever stays unresolved.
            $parsed = apply_filters('fluent_player/parse_smartcodes', $settings, $context);

            if (
                self::containsSmartcode($parsed) &&
                defined('FLUENTCRM') &&
                function_exists('fluentcrm_get_current_contact')
            ) {
                $subscriber = fluentcrm_get_current_contact();
                if ($subscriber) {
                    $parsed = apply_filters('fluent_crm/parse_campaign_email_text', $parsed, $subscriber);

                    if (self::containsSmartcode($parsed)) {
                        $parsed = apply_filters('fluent_crm/parse_extended_crm_text', $parsed, $subscriber);
                    }
                }
            }

            if (self::containsSmartcode($parsed)) {
                $parsed = self::stripSmartcodes($parsed);
            }

            return $parsed;
        }

        // Early exit: if no smartcodes exist anywhere in the settings, skip traversal
        if (!self::containsSmartcode(json_encode($settings))) {
            return $settings;
        }

        // Top-level text fields that support smartcodes (matches frontend GeneralSettings, etc.)
        $smartcodeFields = ['title'];

        // Nested arrays: each item has these string keys parsed (matches frontend layers/overlays JSX)
        $nestedGroups = [
            'layers'   => ['content', 'title', 'text', 'description', 'button_text', 'button_url', 'url'],
            'overlays' => ['text', 'link'],
        ];

        // Top-level objects with text fields (preset/media email_capture, cta, action_bar – matches PresetEditModal JSX)
        $nestedObjectGroups = [
            'email_capture' => ['placeholder', 'headline', 'button_text', 'bottom_text', 'confirmation_message'],
            'cta'           => ['content'],
            'action_bar'    => ['text', 'button_text', 'button_link'],
        ];

        foreach ($smartcodeFields as $field) {
            if (!empty($settings[$field]) && is_string($settings[$field])) {
                $settings[$field] = self::parseSmartcodes($settings[$field], $context);
            }
        }

        foreach ($nestedObjectGroups as $groupKey => $fields) {
            if (empty($settings[$groupKey]) || !is_array($settings[$groupKey])) {
                continue;
            }
            foreach ($fields as $field) {
                if (!empty($settings[$groupKey][$field]) && is_string($settings[$groupKey][$field])) {
                    $settings[$groupKey][$field] = self::parseSmartcodes($settings[$groupKey][$field], $context);
                }
            }
        }

        foreach ($nestedGroups as $groupKey => $fields) {
            if (empty($settings[$groupKey]) || !is_array($settings[$groupKey])) {
                continue;
            }
            foreach ($settings[$groupKey] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (!empty($item[$field]) && is_string($item[$field])) {
                        $item[$field] = self::parseSmartcodes($item[$field], $context);
                    }
                }
                // Per-layer nested: email_capture (matches EmailLayerSettings JSX)
                if (!empty($item['email_capture']) && is_array($item['email_capture'])) {
                    foreach (['placeholder', 'headline', 'button_text', 'bottom_text', 'confirmation_message'] as $ecField) {
                        if (!empty($item['email_capture'][$ecField]) && is_string($item['email_capture'][$ecField])) {
                            $item['email_capture'][$ecField] = self::parseSmartcodes($item['email_capture'][$ecField], $context);
                        }
                    }
                }
                // Per-layer nested: hotspots (matches HotspotLayerSettings JSX)
                if (!empty($item['hotspots']) && is_array($item['hotspots'])) {
                    foreach ($item['hotspots'] as &$hotspot) {
                        if (is_array($hotspot)) {
                            if (!empty($hotspot['tooltip_text']) && is_string($hotspot['tooltip_text'])) {
                                $hotspot['tooltip_text'] = self::parseSmartcodes($hotspot['tooltip_text'], $context);
                            }
                            if (!empty($hotspot['link']) && is_string($hotspot['link'])) {
                                $hotspot['link'] = self::parseSmartcodes($hotspot['link'], $context);
                            }
                        }
                    }
                    unset($hotspot);
                }
            }
            unset($item);
        }

        return $settings;
    }

    private static function containsSmartcode($value)
    {
        return strpos($value, '{{') !== false || strpos($value, '##') !== false;
    }

    private static function stripSmartcodes($value)
    {
        // Unresolved token (e.g. {{contact.*}} with no CRM contact) — keep its |fallback.
        $value = preg_replace_callback('/\{\{([^}]*)\}\}/', function ($m) {
            return trim(Arr::get(explode('|', $m[1]), 1, ''));
        }, $value);

        $value = preg_replace('/##[^#]*##/', '', $value);

        return trim($value);
    }
}
