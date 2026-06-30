<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class MediaMigrator
{
    /**
     * Migrate Presto Player media items to Fluent Player
     *
     * @param array $ppPostIds PP pp_video_block post IDs to migrate
     * @return array
     */
    public static function migrate($ppPostIds, $force = false)
    {
        $map = get_option(Scanner::MAP_OPTION, []);

        if (!isset($map['media'])) {
            $map['media'] = [];
        }

        _prime_post_caches($ppPostIds, true, true);
        $videosLookup = self::batchLoadVideosTable($ppPostIds);

        $canMigrateTags = taxonomy_exists('pp_video_tag') && taxonomy_exists('flp_media_tag');
        if ($canMigrateTags) {
            update_object_term_cache($ppPostIds, 'pp_video_block');
        }

        $migrated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($ppPostIds as $ppPostId) {
            $ppPostId = (int) $ppPostId;

            $existingFpId = !empty($map['media'][$ppPostId]) ? (int) $map['media'][$ppPostId] : 0;

            if ($existingFpId && !$force) {
                $skipped++;
                continue;
            }

            try {
                $fpMediaId = self::migrateSingle($ppPostId, $map, $videosLookup, $existingFpId, $canMigrateTags);

                if ($fpMediaId > 0) {
                    $map['media'][$ppPostId] = $fpMediaId;
                    $migrated++;
                } else {
                    $map['media'][$ppPostId] = 0;
                    $skipped++;
                }
            } catch (\Exception $e) {
                $ppPost = get_post($ppPostId);
                $errors[] = [
                    'id'    => $ppPostId,
                    'title' => $ppPost ? Sanitizer::sanitizeTextField($ppPost->post_title) : "(ID: {$ppPostId})",
                    'error' => __('Failed to migrate this media item.', 'fluent-player'),
                ];
            }
        }

        update_option(Scanner::MAP_OPTION, $map, false);

        return [
            'migrated' => $migrated,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'message'  => sprintf(
                /* translators: 1: number of media items successfully migrated, 2: number of media items skipped. */
                __('%1$d media migrated, %2$d skipped.', 'fluent-player'),
                $migrated,
                $skipped
            ),
        ];
    }

    /**
     * Migrate analytics visits from Presto Player to Fluent Player (Pro only)
     *
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public static function migrateVisits($offset = 0, $limit = 50)
    {
        if (!class_exists('FluentPlayerPro\App\Models\Visit')) {
            return [
                'migrated'  => 0,
                'remaining' => 0,
                'message'   => __('Visit model not available.', 'fluent-player'),
            ];
        }

        global $wpdb;

        $visitsTable = $wpdb->prefix . 'presto_player_visits';
        $fpVisitsTable = $wpdb->prefix . 'flp_visits';

        if (!self::tableExists($visitsTable) || !self::tableExists($fpVisitsTable)) {
            return [
                'migrated'  => 0,
                'remaining' => 0,
                'message'   => __('Required visits table not found.', 'fluent-player'),
            ];
        }

        $videoToMediaMap = self::buildVideoToMediaMap();

        if (empty($videoToMediaMap)) {
            return [
                'migrated'  => 0,
                'remaining' => 0,
                'message'   => __('No media mapping found. Migrate media first.', 'fluent-player'),
            ];
        }

        $videoIds = array_keys($videoToMediaMap);
        $placeholders = implode(',', array_fill(0, count($videoIds), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration code: $visitsTable comes from Scanner (hardcoded prefix), $placeholders are dynamic %d integer format strings, one-shot count + batch read during visit migration, caching not appropriate.
        $totalRemaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$visitsTable}` WHERE deleted_at IS NULL AND video_id IN ({$placeholders})",
            ...$videoIds
        ));

        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT video_id, user_id, ip_address, duration, created_at, updated_at
             FROM `{$visitsTable}`
             WHERE deleted_at IS NULL AND video_id IN ({$placeholders})
             ORDER BY id ASC LIMIT %d OFFSET %d",
            ...array_merge($videoIds, [$limit, $offset])
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

        $durationMap = self::buildMediaDurationMap($videoToMediaMap);

        $migrated = 0;
        $rows = [];

        foreach ($visits as $visit) {
            $fpMediaId = $videoToMediaMap[$visit->video_id] ?? null;

            if (!$fpMediaId) {
                continue;
            }

            $visitDuration = (float) ($visit->duration ?? 0);
            $mediaDuration = $durationMap[$fpMediaId] ?? 0;
            $percentage = ($mediaDuration > 0 && $visitDuration > 0)
                ? min(100, (int) round(($visitDuration / $mediaDuration) * 100))
                : 0;

            $rows[] = [
                'media_id'   => (int) $fpMediaId,
                'user_id'    => $visit->user_id ?: null,
                'ip_address' => Sanitizer::sanitizeTextField($visit->ip_address ?? ''),
                'country'    => '',
                'device'     => '',
                'browser'    => '',
                'duration'   => $visitDuration,
                'percentage' => $percentage,
                'created_at' => $visit->created_at,
                'updated_at' => $visit->updated_at ?? $visit->created_at,
            ];

            $migrated++;
        }

        if (!empty($rows)) {
            \FluentPlayerPro\App\Models\Visit::insert($rows);
        }

        $remaining = max(0, $totalRemaining - $offset - $limit);

        if ($remaining === 0) {
            $map = get_option(Scanner::MAP_OPTION, []);
            $map['visits_migrated'] = true;
            update_option(Scanner::MAP_OPTION, $map, false);
        }

        return [
            'migrated'    => $migrated,
            'remaining'   => $remaining,
            'next_offset' => $offset + $limit,
            'message'     => sprintf(
                /* translators: 1: number of visits migrated in this batch, 2: number of visits still remaining to migrate. */
                __('%1$d visits migrated, %2$d remaining.', 'fluent-player'),
                $migrated,
                $remaining
            ),
        ];
    }

    /**
     * Migrate a single PP media post to FP
     *
     * @param int $ppPostId
     * @param array $map
     * @return int
     */
    private static function migrateSingle($ppPostId, $map, $videosLookup = [], $existingFpId = 0, $canMigrateTags = false)
    {
        $ppPost = get_post($ppPostId);

        if (!$ppPost || $ppPost->post_type !== 'pp_video_block') {
            return 0;
        }

        $block = Scanner::findMediaBlock($ppPost->post_content);

        if (!$block) {
            return 0;
        }

        $blockName = Arr::get($block, 'blockName');
        $attrs = Arr::get($block, 'attrs', []);
        $attrs = self::maybeRefreshBunnyTracks($blockName, $attrs);

        if (Scanner::isProtectedMedia($attrs)) {
            return 0;
        }

        $ppPresetId = Arr::get($attrs, 'preset', 0);
        $fpPresetSlug = null;

        if ($ppPresetId) {
            $mapKey = 'video_' . $ppPresetId;
            $fpPresetSlug = Arr::get($map, 'presets.' . $mapKey);
        }

        $settings = FieldMapper::mapMediaSettings($attrs, $blockName, $fpPresetSlug);
        $settings = self::enrichFromVideosLookup($attrs, $settings, $videosLookup);

        if (empty($settings['src'])) {
            return 0;
        }

        $title = $ppPost->post_title;
        if (empty($title) && !empty($settings['src'])) {
            $title = basename(wp_parse_url($settings['src'], PHP_URL_PATH) ?: '');
        }

        $sanitizedTitle = Sanitizer::sanitizeTextField($title ?: __('Imported Media', 'fluent-player'));
        $postPassword = (string) $ppPost->post_password;
        $postStatus = $ppPost->post_status === 'publish'
            ? (($postPassword !== '' || get_post_meta($ppPostId, 'presto_player_instant_video_pages_enabled', true)) ? 'publish' : 'private')
            : $ppPost->post_status;
        $settings['title'] = $sanitizedTitle;
        $settings['post_status'] = $postStatus;

        // Keep a scheduled post scheduled: without the original date, WP sees a
        // 'future' status with a past date and publishes it immediately.
        $dateArgs = [];
        if ($postStatus === 'future') {
            $dateArgs = [
                'post_date'     => $ppPost->post_date,
                'post_date_gmt' => $ppPost->post_date_gmt,
                'edit_date'     => true,
            ];
        }

        if ($existingFpId && get_post($existingFpId)) {
            wp_update_post(array_merge([
                'ID'            => $existingFpId,
                'post_title'    => $sanitizedTitle,
                'post_status'   => $postStatus,
                'post_password' => $postPassword,
            ], $dateArgs));
            $fpPostId = $existingFpId;
        } else {
            $fpPostId = wp_insert_post(array_merge([
                'post_type'     => 'fluent_player_media',
                'post_title'    => Sanitizer::sanitizeTextField($title ?: __('Imported Media', 'fluent-player')),
                'post_status'   => $postStatus,
                'post_password' => $postPassword,
                'post_content'  => '',
            ], $dateArgs), true);

            if (is_wp_error($fpPostId)) {
                throw new \Exception(esc_html($fpPostId->get_error_message()));
            }

            wp_update_post([
                'ID'           => $fpPostId,
                'post_content' => '<!-- wp:fluent-player/media {"mediaId":' . $fpPostId . '} /-->',
            ]);
        }

        update_post_meta($fpPostId, 'settings', $settings);

        if (!empty($attrs['imageID'])) {
            set_post_thumbnail($fpPostId, absint($attrs['imageID']));
        }

        if ($canMigrateTags) {
            $ppTags = wp_get_object_terms($ppPostId, 'pp_video_tag', ['fields' => 'names']);
            if (!is_wp_error($ppTags) && !empty($ppTags)) {
                wp_set_object_terms($fpPostId, $ppTags, 'flp_media_tag');
            }
        }

        return $fpPostId;
    }

    /**
     * Batch-load all rows from presto_player_videos into a lookup keyed by video ID.
     *
     * @return array video_id => row object
     */
    private static function batchLoadVideosTable($ppPostIds = null)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'presto_player_videos';
        if (!self::tableExists($table)) {
            return [];
        }

        if (!empty($ppPostIds)) {
            $ids = array_values(array_unique(array_map('intval', $ppPostIds)));
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration lookup: $table from $wpdb->prefix; %d placeholders only; batch-scoped read of Presto videos table.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, post_id, src, attachment_id, title FROM `{$table}` WHERE post_id IN ({$placeholders})",
                ...$ids
            ));
            // phpcs:enable
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration lookup: $table from $wpdb->prefix + literal suffix; full read kept as fallback.
            $rows = $wpdb->get_results("SELECT id, post_id, src, attachment_id, title FROM `{$table}`");
        }

        $lookup = [];
        foreach ($rows as $row) {
            $lookup[(int) $row->id] = $row;
        }

        return $lookup;
    }

    /**
     * Enrich media settings using pre-loaded videos lookup (no DB queries)
     *
     * @param array $attrs Block attributes
     * @param array $settings FP settings being built
     * @param array $videosLookup Pre-loaded video_id => row map
     * @return array
     */
    private static function enrichFromVideosLookup($attrs, $settings, $videosLookup)
    {
        $ppVideoId = absint(Arr::get($attrs, 'id', 0));
        if (!$ppVideoId || !isset($videosLookup[$ppVideoId])) {
            return $settings;
        }

        $video = $videosLookup[$ppVideoId];

        if (empty($settings['src']) && !empty($video->src)) {
            $settings['src'] = Sanitizer::escUrlRaw($video->src);
        }

        if (empty($settings['attachment_id']) && !empty($video->attachment_id)) {
            $settings['attachment_id'] = absint($video->attachment_id);

            if (empty($settings['duration'])) {
                $duration = FieldMapper::getDurationFromAttachment(absint($video->attachment_id));
                if ($duration > 0) {
                    $settings['duration'] = $duration;
                }
            }
        }

        return $settings;
    }

    /**
     * Refresh Bunny caption tracks from active Presto Pro before migration.
     *
     * @param string $blockName
     * @param array  $attrs
     * @return array
     */
    private static function maybeRefreshBunnyTracks($blockName, $attrs)
    {
        if ($blockName !== 'presto-player/bunny' || !is_array($attrs)) {
            return $attrs;
        }

        if (!class_exists('\PrestoPlayer\Pro\Blocks\BunnyCDNBlock')) {
            return $attrs;
        }

        try {
            $block = new \PrestoPlayer\Pro\Blocks\BunnyCDNBlock();
            if (!method_exists($block, 'overrideAttributes')) {
                return $attrs;
            }

            $freshAttrs = $block->overrideAttributes($attrs);
            if (!is_array($freshAttrs)) {
                return $attrs;
            }

            if (Arr::has($freshAttrs, 'tracks') && is_array($freshAttrs['tracks'])) {
                $attrs['tracks'] = $freshAttrs['tracks'];
            }
        } catch (\Throwable $e) {
            return $attrs;
        }

        return $attrs;
    }

    /**
     * Build map from PP video_id → FP media_id using migration map
     */
    private static function buildVideoToMediaMap()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'presto_player_videos';
        if (!self::tableExists($table)) {
            return [];
        }

        $map = get_option(Scanner::MAP_OPTION, []);
        $mediaMap = Arr::get($map, 'media', []);

        if (empty($mediaMap)) {
            return [];
        }

        $ppPostIds = array_values(array_unique(array_map('intval', array_keys($mediaMap))));
        $placeholders = implode(',', array_fill(0, count($ppPostIds), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration lookup: $table from $wpdb->prefix; %d placeholders only; filtered by migration-map keys.
        $videos = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id FROM `{$table}` WHERE post_id IN ({$placeholders})",
            ...$ppPostIds
        ));
        // phpcs:enable

        $result = [];
        foreach ($videos as $video) {
            $ppPostId = (int) $video->post_id;
            if (isset($mediaMap[$ppPostId])) {
                $result[(int) $video->id] = $mediaMap[$ppPostId];
            }
        }

        return $result;
    }

    /**
     * Build a map of FP media_id → duration (seconds) from stored settings.
     *
     * @param array $videoToMediaMap PP video_id => FP media_id
     * @return array FP media_id => duration
     */
    private static function buildMediaDurationMap($videoToMediaMap)
    {
        $fpMediaIds = array_unique(array_values($videoToMediaMap));
        $map = [];

        update_meta_cache('post', $fpMediaIds);

        foreach ($fpMediaIds as $fpMediaId) {
            $settings = get_post_meta($fpMediaId, 'settings', true);
            if (is_array($settings) && !empty($settings['duration'])) {
                $map[(int) $fpMediaId] = (float) $settings['duration'];
            }
        }

        return $map;
    }

    /**
     * Delete previously imported visits for all migrated FP media IDs
     */
    public static function deleteImportedVisits()
    {
        if (!class_exists('FluentPlayerPro\App\Models\Visit')) {
            return;
        }

        $map = get_option(Scanner::MAP_OPTION, []);
        $fpMediaIds = array_filter(array_map('absint', array_values(Arr::get($map, 'media', []))));

        if (!empty($fpMediaIds)) {
            \FluentPlayerPro\App\Models\Visit::whereIn('media_id', $fpMediaIds)->delete();
        }

        unset($map['visits_migrated']);
        update_option(Scanner::MAP_OPTION, $map, false);
    }

    private static function tableExists($table)
    {
        return Scanner::tableExists($table);
    }
}
