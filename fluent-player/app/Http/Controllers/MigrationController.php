<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\Scanner;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\PresetMigrator;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\SettingsMigrator;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\MediaMigrator;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\EmailSubmissionMigrator;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\PlaylistMigrator;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\ContentRewriter;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\ContentReverter;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class MigrationController extends Controller
{
    private static $allowedTypes = ['all', 'youtube', 'self_hosted', 'vimeo', 'audio', 'bunny'];

    /**
     * Detect if Presto Player data exists
     */
    public function detect()
    {
        try {
            $result = Scanner::detect();

            $map = get_option(Scanner::MAP_OPTION, []);
            $result['migration_history'] = self::getMigrationSummary($map);

            return $this->sendSuccess($result);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Failed to detect Presto Player data.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Scan Presto Player data for migration preview
     */
    public function scan(Request $request)
    {
        try {
            $type = Sanitizer::sanitizeKey($request->get('type', 'all'));
            $search = Sanitizer::sanitizeTextField($request->get('search', ''));

            if (!in_array($type, self::$allowedTypes, true)) {
                $type = 'all';
            }

            return $this->sendSuccess(Scanner::scan($type, $search));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Failed to scan Presto Player data.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Migrate presets from Presto Player
     */
    public function migratePresets(Request $request)
    {
        try {
            if (!Helper::hasPro()) {
                return $this->sendError([
                    'message' => __('Preset migration requires FluentPlayer Pro.', 'fluent-player')
                ], 403);
            }

            $presetIds = self::parseIntArray($request->get('preset_ids', []));

            $force = $request->get('force') === '1';

            return $this->sendSuccess(PresetMigrator::migrate($presetIds, $force));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Preset migration failed.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Migrate global settings from Presto Player
     */
    public function migrateSettings()
    {
        try {
            return $this->sendSuccess(SettingsMigrator::migrate());
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Settings migration failed.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Migrate media items from Presto Player (batch)
     */
    public function migrateMedia(Request $request)
    {
        try {
            $postIds = array_slice(self::parseIntArray($request->get('post_ids', [])), 0, 100);

            if (empty($postIds)) {
                return $this->sendError([
                    'message' => __('No media IDs provided.', 'fluent-player')
                ], 400);
            }

            $force = $request->get('force') === '1';

            return $this->sendSuccess(MediaMigrator::migrate($postIds, $force));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Media migration failed.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Migrate playlists from Presto Player
     */
    public function migratePlaylists(Request $request)
    {
        try {
            if (!Helper::hasPro()) {
                return $this->sendError([
                    'message' => __('Playlist migration requires FluentPlayer Pro.', 'fluent-player')
                ], 403);
            }

            $force = $request->get('force') === '1';

            return $this->sendSuccess(PlaylistMigrator::migrate($force));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Playlist migration failed.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Migrate analytics visits from Presto Player (batch)
     */
    public function migrateVisits(Request $request)
    {
        try {
            if (!Helper::hasPro()) {
                return $this->sendError([
                    'message' => __('Analytics migration requires FluentPlayer Pro.', 'fluent-player')
                ], 403);
            }

            $force = $request->get('force') === '1';
            $map = get_option(Scanner::MAP_OPTION, []);

            if (!empty($map['visits_migrated']) && !$force) {
                return $this->sendSuccess([
                    'migrated'  => 0,
                    'remaining' => 0,
                    'message'   => __('Visits already migrated.', 'fluent-player'),
                ]);
            }

            $offset = absint($request->get('offset', 0));
            $limit = min(absint($request->get('limit', 50)), 100);

            if ($force && $offset === 0) {
                MediaMigrator::deleteImportedVisits();
            }

            return $this->sendSuccess(MediaMigrator::migrateVisits($offset, $limit));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Visits migration failed.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Migrate email submissions from Presto Player (batch).
     * Depends on the migration map populated by migrateMedia/migratePresets.
     */
    public function migrateEmailSubmissions(Request $request)
    {
        try {
            if (!Helper::hasPro()) {
                return $this->sendError([
                    'message' => __('Email submission migration requires FluentPlayer Pro.', 'fluent-player'),
                ], 403);
            }

            $afterId = absint($request->get('after_id', 0));
            $limit = min(absint($request->get('limit', 100)), 100);

            return $this->sendSuccess(EmailSubmissionMigrator::migrate($afterId, $limit));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Email submission migration failed.', 'fluent-player'),
            ], 400);
        }
    }

    /**
     * Rewrite Presto Player blocks/shortcodes in post content
     */
    public function rewriteContent(Request $request)
    {
        try {
            $postIds = array_slice(self::parseIntArray($request->get('post_ids', [])), 0, 50);

            if (empty($postIds)) {
                return $this->sendError([
                    'message' => __('No post IDs provided.', 'fluent-player')
                ], 400);
            }

            return $this->sendSuccess(ContentRewriter::rewrite($postIds));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Content rewrite failed.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Reset migration — delete all imported data and clear mapping
     */
    public function reset(Request $request)
    {
        try {
            $deleteData = (bool) $request->get('delete_data', false);
            $reverted = 0;
            $deleted = ['media' => 0, 'playlists' => 0, 'presets' => 0, 'email_submissions' => 0];

            if ($deleteData) {
                $map = get_option(Scanner::MAP_OPTION, []);

                $revertResult = ContentReverter::revert($map);
                $reverted = Arr::get($revertResult, 'reverted', 0);

                SettingsMigrator::revert();

                $deleted = self::deleteImportedData($map);
            }

            delete_option(Scanner::MAP_OPTION);

            return $this->sendSuccess([
                'message'                    => __('Migration has been reset.', 'fluent-player'),
                'deleted_media'              => Arr::get($deleted, 'media', 0),
                'deleted_presets'            => Arr::get($deleted, 'presets', 0),
                'deleted_playlists'          => Arr::get($deleted, 'playlists', 0),
                'deleted_email_submissions'  => Arr::get($deleted, 'email_submissions', 0),
                'reverted_posts'             => $reverted,
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Migration reset failed.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Delete all previously imported FP media and presets from the migration map.
     *
     * @param array $map
     * @return array Counts of deleted items
     */
    private static function deleteImportedData($map)
    {
        $deletedMedia = 0;
        $deletedPresets = 0;
        $deletedPlaylists = 0;
        $deletedEmailSubmissions = EmailSubmissionMigrator::deleteImported();

        $mediaMap = Arr::get($map, 'media', []);
        $mediaIds = array_filter(array_map('absint', array_values($mediaMap)));
        $playlistMap = Arr::get($map, 'playlists', []);
        $playlistIds = array_filter(array_map('absint', array_values($playlistMap)));

        _prime_post_caches(array_merge($mediaIds, $playlistIds), false, false);

        foreach ($mediaIds as $fpMediaId) {
            if (get_post_type($fpMediaId) === 'fluent_player_media') {
                wp_delete_post($fpMediaId, true);
                $deletedMedia++;
            }
        }

        foreach ($playlistIds as $fpPlaylistId) {
            if (get_post_type($fpPlaylistId) === 'fluent_playlist') {
                wp_delete_post($fpPlaylistId, true);
                $deletedPlaylists++;
            }
        }

        $presetMap = Arr::get($map, 'presets', []);
        $existingPresets = \FluentPlayer\App\Services\PresetService::all();

        foreach ($presetMap as $fpPresetSlug) {
            if (isset($existingPresets[$fpPresetSlug])) {
                \FluentPlayer\App\Services\PresetService::delete($fpPresetSlug);
                $deletedPresets++;
            }
        }

        return [
            'media'              => $deletedMedia,
            'playlists'          => $deletedPlaylists,
            'presets'            => $deletedPresets,
            'email_submissions'  => $deletedEmailSubmissions,
        ];
    }

    /**
     * Build a summary of prior migration from the stored map.
     *
     * @param array $map
     * @return array|null
     */
    private static function getMigrationSummary($map)
    {
        if (empty($map)) {
            return null;
        }

        $mediaMap = Arr::get($map, 'media', []);

        global $wpdb;
        $mappedPpIds = array_map('intval', array_keys($mediaMap));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name from $wpdb->posts
        $where = "WHERE post_type = 'pp_video_block' AND post_status IN ('publish', 'draft', 'private')"
            . " AND post_content LIKE '%wp:presto-player/%'"
            . " AND post_content NOT LIKE '%\"visibility\":\"private\"%'"
            . " AND post_content NOT LIKE '%\"visibility\": \"private\"%'";

        if (!Helper::hasPro()) {
            $where .= " AND post_content NOT LIKE '%wp:presto-player/bunny%'";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration history: $wpdb->posts + hardcoded WHERE built from internal logic; one-shot count for UI display.
        $ppPostIds = $wpdb->get_col("SELECT ID FROM `{$wpdb->posts}` {$where}");
        $unmappedMedia = empty($ppPostIds) ? 0 : count(array_diff(array_map('intval', $ppPostIds), $mappedPpIds));

        $unmappedPresets = 0;
        $presetMap = Arr::get($map, 'presets', []);
        if (Helper::hasPro()) {
            $presetsTable = $wpdb->prefix . 'presto_player_presets';
            if (Scanner::tableExists($presetsTable)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration history: $presetsTable from $wpdb->prefix + literal suffix; one-shot count.
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$presetsTable}` WHERE deleted_at IS NULL");
                $mappedCount = count(array_filter(array_keys($presetMap), function ($key) {
                    return strpos($key, 'video_') === 0;
                }));
                $unmappedPresets = max(0, $count - $mappedCount);
            }
        }

        return [
            'has_history'       => true,
            'settings'          => !empty($map['settings']),
            'presets'           => count($presetMap),
            'media'             => count(array_filter($mediaMap)),
            'playlists'         => isset($map['playlists']) ? count($map['playlists']) : 0,
            'visits'            => !empty($map['visits_migrated']),
            'email_submissions' => !empty($map['email_submissions_migrated']),
            'content_rewrite'   => (int) Arr::get($map, 'content_rewrite', 0),
            'media_map'         => $mediaMap,
            'unmapped_media'    => $unmappedMedia,
            'unmapped_presets'  => $unmappedPresets,
        ];
    }

    private static function parseIntArray($value)
    {
        if (is_string($value)) {
            $value = array_filter(explode(',', $value));
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('absint', $value),
            function ($id) { return $id > 0; }
        ));
    }

}
