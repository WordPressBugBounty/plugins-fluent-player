<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Models\EmailCollection;
use FluentPlayer\Framework\Support\Arr;

/**
 * Migrates pp_email_submission CPT into flp_email_collections.
 *
 * Submission `video_id` is the presto_player_videos PK (not a post ID), so it
 * must be joined through that table before consulting the media migration map.
 * Rows with no map hit (dashboard-entered or unmigrated media) are kept with
 * media_id = null — they're still valid leads.
 */
class EmailSubmissionMigrator
{
    const POST_TYPE = 'pp_email_submission';

    /**
     * Total number of published pp_email_submission posts.
     *
     * Uses a direct DB query rather than wp_count_posts() so the migration
     * works even if PP Pro has been deactivated but the data is still in the DB.
     */
    public static function count()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scanner: literal post type, one-shot count for UI display.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = %s AND post_status = 'publish'",
            self::POST_TYPE
        ));
    }

    /**
     * Migrate a batch of submissions using keyset pagination.
     *
     * @param int $afterId Cursor — process posts with ID > $afterId.
     * @param int $limit   Max submissions to process this call (capped at 100).
     * @return array{migrated:int, skipped:int, total:int, done:bool, next_cursor:int}
     */
    public static function migrate($afterId = 0, $limit = 100)
    {
        $afterId = max(0, (int) $afterId);
        $limit = max(1, min(100, (int) $limit));

        $total = self::count();
        if ($total === 0) {
            self::markDone();
            return [
                'migrated'    => 0,
                'skipped'     => 0,
                'total'       => 0,
                'done'        => true,
                'next_cursor' => $afterId,
            ];
        }

        global $wpdb;
        // +1 peek-ahead so a full-`limit` final batch signals done without an extra empty query.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination: literal post type via %s, cursor via %d; avoids the O(n^2) OFFSET scan on large submission sets.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM `{$wpdb->posts}`
             WHERE post_type = %s AND post_status = 'publish' AND ID > %d
             ORDER BY ID ASC LIMIT %d",
            self::POST_TYPE,
            $afterId,
            $limit + 1
        ));
        $hasMore = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);

        if (empty($ids)) {
            self::markDone();
            return [
                'migrated'    => 0,
                'skipped'     => 0,
                'total'       => $total,
                'done'        => true,
                'next_cursor' => $afterId,
            ];
        }

        _prime_post_caches($ids, false, true);
        $posts = array_filter(array_map('get_post', $ids));

        $submissions = [];
        $batchVideoIds = [];
        $batchEmails = [];
        foreach ($posts as $post) {
            $email = sanitize_email((string) get_post_meta($post->ID, 'email', true));
            if (empty($email)) {
                continue;
            }

            $videoId = (int) get_post_meta($post->ID, 'video_id', true);
            $presetId = (int) get_post_meta($post->ID, 'preset_id', true);
            $firstname = sanitize_text_field((string) get_post_meta($post->ID, 'firstname', true));
            $lastname = sanitize_text_field((string) get_post_meta($post->ID, 'lastname', true));

            $submissions[] = [
                'email'      => $email,
                'video_id'   => $videoId,
                'preset_id'  => $presetId,
                'firstname'  => $firstname,
                'lastname'   => $lastname,
                'created_at' => $post->post_date ?: current_time('mysql'),
            ];

            if ($videoId > 0) {
                $batchVideoIds[$videoId] = true;
            }
            $batchEmails[$email] = true;
        }

        $batchVideoIds = array_keys($batchVideoIds);
        $batchEmails = array_keys($batchEmails);

        $map = get_option(Scanner::MAP_OPTION, []);
        $mediaMap = Arr::get($map, 'media', []);
        $presetMap = Arr::get($map, 'presets', []);
        $videoIdToFpMedia = self::lookupBatchVideoIds($batchVideoIds, $mediaMap);

        $existingKeys = self::loadExistingDedupKeys($batchEmails);

        $rowsToInsert = [];
        $skipped = 0;
        foreach ($submissions as $s) {
            $mediaId = isset($videoIdToFpMedia[$s['video_id']])
                ? (int) $videoIdToFpMedia[$s['video_id']]
                : null;

            $presetSlug = null;
            if ($s['preset_id'] > 0) {
                $presetMapKey = 'video_' . $s['preset_id'];
                if (isset($presetMap[$presetMapKey])) {
                    $presetSlug = (string) $presetMap[$presetMapKey];
                }
            }

            // Mirror FP runtime dedup: (email, media, preset). Same email on
            // two presets of one media = two rows.
            $dedupKey = $s['email'] . '|' . ($mediaId ?? '') . '|' . ($presetSlug ?? '');
            if (isset($existingKeys[$dedupKey])) {
                $skipped++;
                continue;
            }
            // Dedup within the batch too, not just against the DB.
            $existingKeys[$dedupKey] = true;

            $meta = ['_fp_migrated_from' => 'pp'];
            if ($s['firstname'] !== '') {
                $meta['firstname'] = $s['firstname'];
            }
            if ($s['lastname'] !== '') {
                $meta['lastname'] = $s['lastname'];
            }

            $rowsToInsert[] = [
                'email'       => $s['email'],
                'media_id'    => $mediaId,
                'preset_slug' => $presetSlug,
                'meta'        => wp_json_encode($meta),
                'created_at'  => $s['created_at'],
                'updated_at'  => $s['created_at'],
            ];
        }

        $migrated = self::bulkInsert($rowsToInsert);

        $nextCursor = (int) end($ids);
        $done = !$hasMore;

        if ($done) {
            self::markDone();
        }

        return [
            'migrated'    => $migrated,
            'skipped'     => $skipped,
            'total'       => $total,
            'done'        => $done,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Returns videos.id => fp_media_post_id for the IDs in this batch only.
     * IN clause is bounded by batch size, not total migrated media count.
     */
    private static function lookupBatchVideoIds($batchVideoIds, $mediaMap)
    {
        if (empty($batchVideoIds) || empty($mediaMap)) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'presto_player_videos';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix + hardcoded suffix
        $exists = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($batchVideoIds), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration lookup: $table from $wpdb->prefix; %d placeholders only; bounded by batch size.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id FROM `{$table}` WHERE id IN ({$placeholders})",
            ...$batchVideoIds
        ));
        // phpcs:enable

        $result = [];
        foreach ($rows as $row) {
            $ppPostId = (int) $row->post_id;
            if (!empty($mediaMap[$ppPostId])) {
                $result[(int) $row->id] = (int) $mediaMap[$ppPostId];
            }
        }

        return $result;
    }

    /**
     * Pre-loads existing (email, media_id) pairs for in-memory dedup —
     * replaces N count queries with one whereIn.
     */
    private static function loadExistingDedupKeys($batchEmails)
    {
        if (empty($batchEmails)) {
            return [];
        }

        $rows = EmailCollection::whereIn('email', $batchEmails)
            ->get(['email', 'media_id', 'preset_slug']);

        $keys = [];
        foreach ($rows as $row) {
            // Model's integer cast turns a null DB column into 0 on read; flip back.
            $mediaId = $row->media_id;
            if ($mediaId === 0 || $mediaId === '0') {
                $mediaId = null;
            }
            $keys[$row->email . '|' . ($mediaId ?? '') . '|' . ((string) $row->preset_slug)] = true;
        }

        return $keys;
    }

    /**
     * Bulk INSERT via $wpdb — bypasses the ORM so nulls survive the integer
     * cast and the whole batch goes in one round-trip. No model `created`
     * events fire (fine for historical data).
     */
    private static function bulkInsert($rows)
    {
        if (empty($rows)) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'flp_email_collections';

        $columns = ['email', 'media_id', 'preset_slug', 'meta', 'created_at', 'updated_at'];
        $placeholderSets = [];
        $values = [];

        foreach ($rows as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                if ($value === null) {
                    // $wpdb->prepare coerces null → '', so inject NULL literally.
                    $rowPlaceholders[] = 'NULL';
                } elseif (in_array($col, ['media_id'], true)) {
                    $rowPlaceholders[] = '%d';
                    $values[] = (int) $value;
                } else {
                    $rowPlaceholders[] = '%s';
                    $values[] = (string) $value;
                }
            }
            $placeholderSets[] = '(' . implode(',', $rowPlaceholders) . ')';
        }

        $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $columns) . "`) VALUES " . implode(',', $placeholderSets);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table from $wpdb->prefix; all values bound via %s/%d in prepare(); NULL is literal (see above).
        $result = empty($values)
            ? $wpdb->query($sql)
            : $wpdb->query($wpdb->prepare($sql, $values));
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $result === false ? 0 : (int) $result;
    }

    /**
     * Delete rows previously inserted by this migrator. Marker lives in the
     * `meta` JSON column (`_fp_migrated_from: pp`) so FP-native captures
     * sharing the same email/media are untouched.
     */
    public static function deleteImported()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flp_email_collections';
        $pattern = '%' . $wpdb->esc_like('"_fp_migrated_from":"pp"') . '%';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset path: $table from $wpdb->prefix; LIKE pattern escaped via esc_like().
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table}` WHERE meta LIKE %s",
            $pattern
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * Flip the map flag the admin "Email submissions imported" badge reads.
     */
    private static function markDone()
    {
        $map = get_option(Scanner::MAP_OPTION, []);
        if (empty($map['email_submissions_migrated'])) {
            $map['email_submissions_migrated'] = true;
            update_option(Scanner::MAP_OPTION, $map, false);
        }
    }
}
