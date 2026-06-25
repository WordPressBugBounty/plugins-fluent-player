<?php

namespace FluentPlayer\App\Models;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Services\PresetService;
use FluentPlayer\Framework\Database\Orm\Model;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Collection;
use FluentPlayer\Framework\Support\Serializer;

class Media extends Model
{
    public static $postType = 'fluent_player_media';

    public static function boot()
    {
        parent::boot();
    }

    public function save($options = [])
    {
        $postStatus = isset($this->post_status) ? $this->post_status : 'draft';
        $attributes = $this->getAttributes();

        if ($this->id) {
            $postData = [
                'ID'          => $this->id,
                'post_title'  => $this->title,
                'post_status' => $postStatus,
            ];

            if (array_key_exists('post_content', $attributes)) {
                $postData['post_content'] = $this->post_content;
            }

            wp_update_post($postData);
        } else {
            $mediaId = wp_insert_post([
                'post_title'  => $this->title,
                'post_type'   => self::$postType,
                'post_status' => $postStatus,
            ]);

            if (is_wp_error($mediaId)) {
                throw new \Exception(esc_html($mediaId->get_error_message()));
            }

            $post = get_post($mediaId);
            if ($post && empty($post->post_content)) {
                $content = array_key_exists('post_content', $attributes)
                    ? $this->post_content
                    : '<!-- wp:fluent-player/media {"mediaId":' . $mediaId . '} /-->';

                // Update the post with content
                wp_update_post([
                    'ID'           => $post->ID,
                    'post_content' => $content
                ]);
            }

            $this->id = $mediaId;
        }

        $this->saveMediaSettings();

        return self::find($this->id);
    }

    public function delete()
    {
        $mediaId = isset($this->id) ? $this->id : (isset($this->ID) ? $this->ID : null);
        if ($mediaId === null) {
            return false;
        }
        return self::deleteById($mediaId);
    }

    public static function deleteById($id)
    {
        $media = self::find($id);
        if ($media) {
            return wp_delete_post($id, true);
        }
        return false;
    }

    protected function saveMediaSettings()
    {
        if ($settings = Arr::get($this, 'settings')) {
            update_post_meta($this->id, 'settings', $settings);
            // Invalidate the request-level cache so subsequent find() calls
            // return the freshly saved settings, not stale cached data.
            unset(static::$mediaSettingsCache[$this->id]);
        }
    }

    /**
     * Request-level cache for media settings
     */
    protected static $mediaSettingsCache = [];

    /**
     * Batch load media settings for multiple media items
     *
     * @param array $media_items Array of media objects or post IDs
     * @return void
     */
    public static function batchLoadMediaSettings($media_items)
    {
        if (empty($media_items)) {
            return;
        }

        // Extract post IDs that aren't already cached
        $post_ids = [];
        $post_id_map = []; // Map post_id to media object for post_status

        foreach ($media_items as $media) {
            $post_id = is_object($media) ? $media->ID : $media;
            if (!isset(static::$mediaSettingsCache[$post_id])) {
                $post_ids[] = $post_id;
                if (is_object($media)) {
                    $post_id_map[$post_id] = $media;
                }
            }
        }

        if (empty($post_ids)) {
            return; // All items are already cached
        }

        // Batch load settings meta for all post IDs using ORM
        $results = Post::query()
            ->from('postmeta')
            ->select(['post_id', 'meta_value'])
            ->whereIn('post_id', $post_ids)
            ->where('meta_key', 'settings')
            ->get();

        // Process results and store in request-level cache
        foreach ($results as $row) {
            $post_id = (int)(is_object($row) ? $row->post_id : $row['post_id']);
            $meta_value = is_object($row) ? $row->meta_value : $row['meta_value'];
            $settings = maybe_unserialize($meta_value);

            // Ensure settings is an array
            if (!is_array($settings)) {
                if (Serializer::isSerialized($settings)) {
                    $settings = maybe_unserialize($settings);
                } else if (Serializer::isJson($settings)) {
                    $settings = json_decode($settings, true);
                }
                if (!is_array($settings)) {
                    $settings = [];
                }
            }

            if (!empty($settings)) {
                // Get post_status from media object if available
                if (isset($post_id_map[$post_id])) {
                    $post_status = $post_id_map[$post_id]->post_status;
                } else {
                    $post_status = get_post_status($post_id);
                }

                $settings['post_status'] = $post_status;

                // Add default preset slug if not set
                if (!Arr::get($settings, 'preset_slug')) {
                    $settings['preset_slug'] = PresetService::getDefaultSlug();
                }
            }

            // Store in request-level cache
            static::$mediaSettingsCache[$post_id] = $settings;
        }

        // Cache null for posts that don't have settings meta
        foreach ($post_ids as $post_id) {
            if (!isset(static::$mediaSettingsCache[$post_id])) {
                static::$mediaSettingsCache[$post_id] = null;
            }
        }
    }

    public static function getMediaSettings($media)
    {
        $post_id = is_object($media) ? $media->ID : $media;

        // Check request-level cache first
        if (isset(static::$mediaSettingsCache[$post_id])) {
            $settings = static::$mediaSettingsCache[$post_id];
            // Ensure post_status is current
            if (is_object($media) && is_array($settings) && isset($settings['post_status'])) {
                $settings['post_status'] = $media->post_status;
            }
            return $settings;
        }

        $settings = get_post_meta($post_id, 'settings', true);

        // Handle legacy double-serialized data (pre-1.0.2 stored serialize() + update_post_meta)
        if (is_string($settings)) {
            $settings = maybe_unserialize($settings);
        }

        // Ensure settings is an array
        if (!is_array($settings)) {
            if (Serializer::isJson($settings)) {
                $settings = json_decode($settings, true);
            }
            if (!is_array($settings)) {
                $settings = [];
            }
        }

        // Add default preset slug if not set
        if (!empty($settings)) {
            $post_status = is_object($media) ? $media->post_status : get_post_status($post_id);
            $settings['post_status'] = $post_status;
            if (!Arr::get($settings, 'preset_slug')) {
                $settings['preset_slug'] = PresetService::getDefaultSlug();
            }
        }

        // Store in request-level cache
        static::$mediaSettingsCache[$post_id] = $settings;

        return $settings;
    }

    public static function find($id)
    {
        if (!is_numeric($id)) {
            return null;
        }
        $media = Post::where('post_type', self::$postType)
            ->select(['post_title', 'ID', 'post_status', 'post_date'])
            ->find($id);
        if ($media) {
            $media->settings = self::getMediaSettings($media);
        }
        return $media;

    }

    /**
     * Find media with access control.
     *
     * @param int $id The media post ID.
     * @return object|null Media object if visible to current user, null otherwise.
     */
    public static function findVisible($id)
    {
        $media = self::find($id);
        if (!$media) {
            return null;
        }

        if ($media->post_status === 'publish') {
            return $media;
        }

        if (is_user_logged_in() && current_user_can('edit_post', $media->ID)) {
            return $media;
        }

        return null;
    }

    /**
     * Get an editor-facing status notice for unpublished media.
     *
     * Returns an HTML notice for editors/admins when the media is not
     * published (draft, private, pending, etc.). Returns empty string
     * for published media, non-editors, or if the media does not exist.
     *
     * @param object|null $media The media object (from find or findVisible).
     * @return string HTML notice or empty string.
     */
    public static function getStatusNotice($media)
    {
        if (!$media || !current_user_can('edit_posts')) {
            return '';
        }

        if ($media->post_status === 'publish') {
            return '';
        }

        if (is_single() && get_post_type() === self::$postType) {
            return '';
        }

        if ($media->post_status === 'private') {
            $message = __('This media is private and only visible to editors.', 'fluent-player');
        } else {
            $message = __('This media is in draft and not visible to the public.', 'fluent-player');
        }

        return '<div class="fluent-player-notice">' . esc_html($message) . '</div>';
    }


    public static function paginate(Request $request)
    {
        $mediaService = new \FluentPlayer\App\Services\MediaService();
        $withSettings = true;

        if ($request->exists('with_settings')) {
            $rawWithSettings = $request->get('with_settings');
            $normalizedWithSettings = filter_var($rawWithSettings, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $withSettings = $normalizedWithSettings === null ? (bool) $rawWithSettings : $normalizedWithSettings;
        }

        $tags = $request->tags ?? [];
        if (empty($tags) && !empty($request->tag)) {
            $tags = [$request->tag];
        }

        return $mediaService->paginate([
            'per_page'      => $request->per_page,
            'status'        => $request->status,
            'orderby'       => $request->orderby,
            'order'         => $request->order,
            'query'         => $request->query ?? '',
            'tag'           => $request->tag ?? '',
            'tags'          => $tags,
            'provider'      => $request->provider ?? '',
            'media_type'    => $request->media_type ?? '',
        ], $withSettings);
    }

    public static function search($attr)
    {
        $query = sanitize_text_field(Arr::get($attr, 'q', ''));
        $mediaIds = Arr::get($attr, 'medias');
        $offset = intval(Arr::get($attr, 'offset'));
        $limit = intval(Arr::get($attr, 'limit'));
        $status = sanitize_text_field(Arr::get($attr, 'status', ''));
        $orderBy = sanitize_text_field(Arr::get($attr, 'order_by', 'DESC'));
        $orderBy = strtoupper($orderBy);
        if (!in_array($orderBy, ['ASC', 'DESC'])) {
            $orderBy = 'DESC';
        }

        if ($mediaIds) {
            if (is_string($mediaIds)) {
                $mediaIds = \json_decode($mediaIds, true);
            }
            $mediaIds = array_filter(array_map('intval', $mediaIds));
        }

        $medias = Post::latest('ID')
            ->select(['post_title', 'ID', 'post_status', 'post_date'])
            ->where('post_type', self::$postType)
            ->where('post_status', '!=', 'auto-draft')
            ->when($query, function ($q) use ($query) {
                global $wpdb;
                $escaped = $wpdb->esc_like($query);
                return $q->where(function ($subQuery) use ($escaped) {
                    $subQuery->where('post_title', 'LIKE', '%' . $escaped . '%')
                        ->orWhere('ID', 'LIKE', '%' . $escaped . '%')
                        ->orWhere('post_status', 'LIKE', '%' . $escaped . '%');
                });
            })
            ->when($mediaIds && is_array($mediaIds), function ($q) use ($mediaIds) {
                return $q->whereIn('ID', $mediaIds);
            })
            ->when($status, function ($q) use ($status) {
                return $q->where('post_status', $status);
            })
            ->when($offset, function ($q) use ($offset) {
                return $q->skip($offset);
            })->when($limit, function ($q) use ($limit) {
                return $q->take($limit);
            })->when($orderBy, function ($q) use ($orderBy) {
                return $q->orderBy('ID', $orderBy);
            })->get();

        if ($mediaIds && is_array($mediaIds)) {
            $mediaMap = [];
            foreach ($medias as $media) {
                $id = isset($media->ID) ? $media->ID : (isset($media->id) ? $media->id : null);
                if ($id) {
                    $mediaMap[$id] = $media;
                }
            }
            $sortedMedias = [];
            foreach ($mediaIds as $id) {
                if (isset($mediaMap[$id])) {
                    $sortedMedias[] = $mediaMap[$id];
                }
            }
            $medias = new Collection($sortedMedias);
        }

        // Batch load media settings for all items
        self::batchLoadMediaSettings($medias->all());

        $mediasArray = [];
        foreach ($medias as $item) {
            $itemId = isset($item->ID) ? $item->ID : (isset($item->id) ? $item->id : null);
            if ($itemId) {
                $item->settings = self::getMediaSettings($item);
            }
            $mediasArray[] = $item;
        }

        if ($medias instanceof Collection) {
            return new Collection($mediasArray);
        }
        return $mediasArray;
    }

    public static function mergePresetSettings($settings)
    {
        if (!is_array($settings)) {
            return $settings;
        }

        $presetSlug = Arr::get($settings, 'preset_slug');
        $preset = $presetSlug ? PresetService::find($presetSlug) : PresetService::getDefault();

        if (!$preset || !Arr::get($preset, 'settings')) {
            return $settings;
        }

        // Ambient is a Pro-only preset; on free, fall back to Minimal so the
        // muted-autoplay-loop-no-controls behavior is never applied. Also drop the
        // ambient-specific flat overrides a prior Pro selection may have baked into
        // the media settings, since those would otherwise win the array_merge below.
        if (!Helper::hasPro() && PresetService::isAmbientPreset($preset)) {
            $fallback = PresetService::find('minimal');
            if ($fallback && Arr::get($fallback, 'settings')) {
                $preset = $fallback;
                $settings['preset_slug'] = Arr::get($fallback, 'slug', 'minimal');

                $ambientFlatKeys = [
                    'mutedAutoplay',
                    'hide_top_controls',
                    'hide_center_controls',
                    'hide_bottom_controls',
                    'video_end_option',
                ];
                foreach ($ambientFlatKeys as $ambientFlatKey) {
                    unset($settings[$ambientFlatKey]);
                }
            }
        }

        $presetSettings = Arr::get($preset, 'settings');

        if (!is_array($presetSettings) || empty($presetSettings)) {
            return $settings;
        }

        if (!isset($settings['preset_slug']) && isset($preset['slug'])) {
            $settings['preset_slug'] = $preset['slug'];
        }

        $merged = self::normalizePresetBehaviorAliases(array_merge($presetSettings, $settings));

        // Resume playback is preset-controlled. A media stores a full snapshot of
        // its behaviors, so a later edit to the assigned preset would otherwise be
        // shadowed by the stale media copy. Re-assert the live preset value — only
        // for an explicitly assigned preset; preset-less media fall back to the
        // global default resolved on the frontend.
        $presetResume = Arr::get($presetSettings, 'behaviors.save_play_position', null);
        if ($presetSlug && $presetResume !== null) {
            $resume = (bool) $presetResume;
            $merged['save_play_position'] = $resume;
            if (!isset($merged['behaviors']) || !is_array($merged['behaviors'])) {
                $merged['behaviors'] = [];
            }
            $merged['behaviors']['save_play_position'] = $resume;
        }

        return $merged;
    }

    /**
     * Backfill flat runtime keys from nested preset behavior settings.
     *
     * Presets store playback behavior under `settings.behaviors.*`, while the
     * player render/runtime paths still read flat keys such as `autoplay`,
     * `mutedAutoplay`, `playsInline`, and `video_end_option`.
     *
     * Media-level flat values remain authoritative. Nested preset behavior
     * values only backfill when the flat runtime key is absent.
     *
     * @param array $settings
     * @return array
     */
    protected static function normalizePresetBehaviorAliases(array $settings)
    {
        $behaviors = Arr::get($settings, 'behaviors', []);

        if (!is_array($behaviors) || empty($behaviors)) {
            return $settings;
        }

        $behaviorMap = [
            'autoplay'           => 'autoplay',
            'muted_autoplay'     => 'mutedAutoplay',
            'plays_inline'       => 'playsInline',
            'save_play_position' => 'save_play_position',
        ];

        foreach ($behaviorMap as $behaviorKey => $flatKey) {
            if (!array_key_exists($flatKey, $settings) && array_key_exists($behaviorKey, $behaviors)) {
                $settings[$flatKey] = $behaviors[$behaviorKey];
            }
        }

        if (!array_key_exists('video_end_option', $settings) && array_key_exists('on_video_end', $behaviors)) {
            $settings['video_end_option'] = $behaviors['on_video_end'] === 'loop' ? 'loop' : 'default';
        }

        return $settings;
    }

    /**
     * Delete all auto-draft media — called via fluentplayer/daily_cleanup cron hook.
     */
    public static function cleanupAutoDrafts()
    {
        do {
            $autoDrafts = Post::select(['ID'])
                ->where('post_type', static::$postType)
                ->where('post_status', 'auto-draft')
                ->limit(100)
                ->get();

            foreach ($autoDrafts as $post) {
                wp_delete_post($post->ID, true);
            }
        } while ($autoDrafts->count() >= 100);
    }
}
