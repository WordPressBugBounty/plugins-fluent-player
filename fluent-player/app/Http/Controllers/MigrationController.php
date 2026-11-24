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
use FluentPlayer\App\Services\Migrations\SourceRegistry;
use FluentPlayer\App\Services\Migrations\AllInOneVideoGallery\MediaMigrator as AiovgMediaMigrator;
use FluentPlayer\App\Services\Migrations\MigrationMap;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class MigrationController extends Controller
{
    private static $allowedTypes = ['all', 'youtube', 'self_hosted', 'vimeo', 'audio', 'bunny'];

    /**
     * Record what we swallow. Every catch here returns a generic message, so
     * without this a support ticket has nothing to go on.
     *
     * @param string     $context
     * @param \Throwable $e
     * @return void
     */
    private static function logFailure($context, $e)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics for a swallowed migration failure.
            error_log(sprintf(
                '[FluentPlayer] migration %s failed: %s in %s:%d',
                $context,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        do_action('fluent_player/migration_failed', $context, $e);
    }

    /**
     * List the importable sources detected on this site — one card each.
     */
    public function sources()
    {
        try {
            $sources = [];

            foreach (SourceRegistry::detected() as $key => $source) {
                // label()/version()/capabilities() can come from a third-party
                // source registered through the public filter — sanitise at the
                // boundary rather than relying on the client to escape.
                $sources[] = [
                    'key'          => $key,
                    'label'        => Sanitizer::sanitizeTextField($source->label()),
                    'version'      => Sanitizer::sanitizeTextField($source->version()),
                    'capabilities' => array_values(array_map(
                        'sanitize_key',
                        (array) $source->capabilities()
                    )),
                ];
            }

            return $this->sendSuccess([
                'sources' => $sources,
                'default' => SourceRegistry::defaultKey(),
            ]);
        } catch (\Throwable $e) {
            self::logFailure('sources', $e);

            return $this->sendError([
                'message' => __('Failed to list migration sources.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * Detect whether a source has data to import.
     */
    public function detect(?Request $request = null)
    {
        $request = $request ?: new Request([], []);

        try {
            $sourceKey = self::resolveSourceKey($request);
            $source = SourceRegistry::get($sourceKey);

            if (!$source) {
                return $this->sendError([
                    'message' => __('Unknown migration source.', 'fluent-player')
                ], 400);
            }

            $result = $source->detect();

            // migration_history is built from Presto-specific tables and block
            // markup; other sources report their own history from their own map.
            if ($sourceKey === 'presto') {
                $map = get_option(Scanner::MAP_OPTION, []);
                $result['migration_history'] = self::getMigrationSummary($map);
            }

            return $this->sendSuccess($result);
        } catch (\Throwable $e) {
            self::logFailure('detect', $e);

            return $this->sendError([
                'message' => __('Failed to detect data to migrate.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * Scan a source's data for the migration preview.
     */
    public function scan(Request $request)
    {
        try {
            $sourceKey = self::resolveSourceKey($request);
            $source = SourceRegistry::get($sourceKey);

            if (!$source) {
                return $this->sendError([
                    'message' => __('Unknown migration source.', 'fluent-player')
                ], 400);
            }

            $type = Sanitizer::sanitizeKey($request->get('type', 'all'));
            $search = Sanitizer::sanitizeTextField($request->get('search', ''));

            // Presto's filter vocabulary is fixed and pre-existing; keep validating
            // it exactly as before so no shipped caller changes behaviour. Other
            // sources validate their own filters in their scanner.
            if ($sourceKey === 'presto' && !in_array($type, self::$allowedTypes, true)) {
                $type = 'all';
            }

            return $this->sendSuccess($source->scan([
                'type'   => $type,
                'search' => $search,
            ]));
        } catch (\Throwable $e) {
            self::logFailure('scan', $e);

            return $this->sendError([
                'message' => __('Failed to scan data to migrate.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * A missing ?source= means Presto Player, so every caller that predates the
     * source registry — the shipped admin UI, WP-CLI, the Functional Cest — keeps
     * hitting the same code path it always did.
     *
     * @param Request $request
     * @return string
     */
    /**
     * Guard a route that only ever runs the Presto migrators.
     *
     * These accepted ?source=aiovg and silently ran the PRESTO import against it.
     * Refusing is the only honest answer until the capability exists elsewhere.
     *
     * @param Request $request
     * @return bool True when the request may proceed.
     */
    private static function isPrestoRequest(Request $request)
    {
        return self::resolveSourceKey($request) === 'presto';
    }

    private static function resolveSourceKey(Request $request)
    {
        $key = Sanitizer::sanitizeKey($request->get('source', ''));

        return $key !== '' ? $key : SourceRegistry::defaultKey();
    }

    /**
     * Migrate presets from Presto Player
     */
    public function migratePresets(Request $request)
    {
        try {
            if (!self::isPrestoRequest($request)) {
                return $this->sendError([
                    'message' => __('Preset migration is only available for Presto Player.', 'fluent-player')
                ], 400);
            }

            if (!Helper::hasPro()) {
                return $this->sendError([
                    'message' => __('Preset migration requires FluentPlayer Pro.', 'fluent-player')
                ], 403);
            }

            $presetIds = self::parseIntArray($request->get('preset_ids', []));

            $force = $request->get('force') === '1';

            return $this->sendSuccess(PresetMigrator::migrate($presetIds, $force));
        } catch (\Throwable $e) {
            self::logFailure('presets', $e);

            return $this->sendError([
                'message' => __('Preset migration failed.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * Migrate global settings from Presto Player
     */
    public function migrateSettings(Request $request)
    {
        try {
            if (!self::isPrestoRequest($request)) {
                return $this->sendError([
                    'message' => __('Settings migration is only available for Presto Player.', 'fluent-player')
                ], 400);
            }

            return $this->sendSuccess(SettingsMigrator::migrate());
        } catch (\Throwable $e) {
            self::logFailure('settings', $e);

            return $this->sendError([
                'message' => __('Settings migration failed.', 'fluent-player')
            ], 500);
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
            $sourceKey = self::resolveSourceKey($request);

            if ($sourceKey === 'aiovg') {
                return $this->sendSuccess(AiovgMediaMigrator::migrate($postIds, $force));
            }

            if ($sourceKey !== 'presto') {
                return $this->sendError([
                    'message' => __('This source does not support media migration.', 'fluent-player')
                ], 400);
            }

            return $this->sendSuccess(MediaMigrator::migrate($postIds, $force));
        } catch (\Throwable $e) {
            self::logFailure('media', $e);

            return $this->sendError([
                'message' => __('Media migration failed.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * Migrate playlists from Presto Player
     */
    public function migratePlaylists(Request $request)
    {
        try {
            if (!self::isPrestoRequest($request)) {
                return $this->sendError([
                    'message' => __('Playlist migration is only available for Presto Player.', 'fluent-player')
                ], 400);
            }

            if (!Helper::hasPro()) {
                return $this->sendError([
                    'message' => __('Playlist migration requires FluentPlayer Pro.', 'fluent-player')
                ], 403);
            }

            $force = $request->get('force') === '1';

            return $this->sendSuccess(PlaylistMigrator::migrate($force));
        } catch (\Throwable $e) {
            self::logFailure('playlists', $e);

            return $this->sendError([
                'message' => __('Playlist migration failed.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * Migrate analytics visits from Presto Player (batch)
     */
    public function migrateVisits(Request $request)
    {
        try {
            if (!self::isPrestoRequest($request)) {
                return $this->sendError([
                    'message' => __('Analytics migration is only available for Presto Player.', 'fluent-player')
                ], 400);
            }

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
        } catch (\Throwable $e) {
            self::logFailure('visits', $e);

            return $this->sendError([
                'message' => __('Visits migration failed.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * Migrate email submissions from Presto Player (batch).
     * Depends on the migration map populated by migrateMedia/migratePresets.
     */
    public function migrateEmailSubmissions(Request $request)
    {
        try {
            if (!self::isPrestoRequest($request)) {
                return $this->sendError([
                    'message' => __('Email submission migration is only available for Presto Player.', 'fluent-player')
                ], 400);
            }

            if (!Helper::hasPro()) {
                return $this->sendError([
                    'message' => __('Email submission migration requires FluentPlayer Pro.', 'fluent-player'),
                ], 403);
            }

            $afterId = absint($request->get('after_id', 0));
            $limit = min(absint($request->get('limit', 100)), 100);

            return $this->sendSuccess(EmailSubmissionMigrator::migrate($afterId, $limit));
        } catch (\Throwable $e) {
            self::logFailure('email_submissions', $e);

            return $this->sendError([
                'message' => __('Email submission migration failed.', 'fluent-player'),
            ], 500);
        }
    }

    /**
     * Rewrite Presto Player blocks/shortcodes in post content
     */
    public function rewriteContent(Request $request)
    {
        try {
            if (!self::isPrestoRequest($request)) {
                return $this->sendError([
                    'message' => __('Content rewriting is only available for Presto Player.', 'fluent-player')
                ], 400);
            }

            $postIds = array_slice(self::parseIntArray($request->get('post_ids', [])), 0, 50);

            if (empty($postIds)) {
                return $this->sendError([
                    'message' => __('No post IDs provided.', 'fluent-player')
                ], 400);
            }

            return $this->sendSuccess(ContentRewriter::rewrite($postIds));
        } catch (\Throwable $e) {
            self::logFailure('content_rewrite', $e);

            return $this->sendError([
                'message' => __('Content rewrite failed.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * Reset migration — delete all imported data and clear mapping
     */
    public function reset(Request $request)
    {
        try {
            $sourceKey = self::resolveSourceKey($request);

            if ($sourceKey === 'aiovg') {
                return $this->sendSuccess(self::resetAiovg($request));
            }

            if ($sourceKey !== 'presto') {
                return $this->sendError([
                    'message' => __('Unknown migration source.', 'fluent-player')
                ], 400);
            }

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
        } catch (\Throwable $e) {
            self::logFailure('reset', $e);

            return $this->sendError([
                'message' => __('Migration reset failed.', 'fluent-player')
            ], 500);
        }
    }

    /**
     * Reset the AIOVG import. Scoped to this source's own map, so a Presto
     * migration on the same site is untouched.
     *
     * @param Request $request
     * @return array
     */
    private static function resetAiovg(Request $request)
    {
        $deleteData = (bool) $request->get('delete_data', false);
        $deletedMedia = 0;

        if ($deleteData) {
            $deletedMedia = AiovgMediaMigrator::deleteImported();
            MigrationMap::forget('aiovg');

            return [
                'message'       => sprintf(
                    /* translators: %d: number of imported media deleted. */
                    _n('%d imported video deleted.', '%d imported videos deleted.', $deletedMedia, 'fluent-player'),
                    $deletedMedia
                ),
                'deleted_media' => $deletedMedia,
            ];
        }

        // Clearing the map while KEEPING the posts strands them: a later reset can
        // no longer find them and a re-import would create duplicates. So a
        // keep-the-data reset must keep the map too.
        return [
            'message'       => __('Imported videos were kept. Nothing was deleted.', 'fluent-player'),
            'deleted_media' => 0,
        ];
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
