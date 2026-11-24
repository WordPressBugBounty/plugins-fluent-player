<?php

namespace FluentPlayer\App\Services\Migrations\AllInOneVideoGallery;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\Migrations\MigrationMap;
use FluentPlayer\Framework\Support\Sanitizer;

/**
 * Imports `aiovg_videos` posts as `fluent_player_media` posts.
 *
 * Non-destructive: AIOVG's own posts and meta are only ever read. The
 * source-id => FluentPlayer-id map lives under the `aiovg` key
 * (MigrationMap::optionName('aiovg')), so re-running is idempotent and a later
 * reset can find exactly what this created without touching a Presto import.
 */
class MediaMigrator
{
    const SOURCE_KEY = 'aiovg';

    /** AIOVG's tag taxonomy (all-in-one-video-gallery/admin/tags.php:112). */
    const SOURCE_TAXONOMY = 'aiovg_tags';

    /**
     * Settings a re-import may overwrite, because AIOVG is their source of truth.
     * Everything else on the media belongs to the user once imported.
     */
    const SOURCE_DERIVED_KEYS = [
        'provider',
        'src',
        'posterSrc',
        'viewType',
        'mediaType',
        'attachment_id',
        'duration',
        'aspectRatio',
        'bunny',
        'title',
        'post_status',
    ];

    /**
     * Stamped on every imported media with the AIOVG post id it came from.
     *
     * This is the durable link, not the option map. It survives a crashed run, lets
     * a re-import reclaim an existing post instead of duplicating it, and lets
     * reset find media whose map entry was never written.
     */
    const SOURCE_ID_META = '_flp_aiovg_source_id';

    /** Seconds after which a reservation left by a crashed run may be taken over. */
    const LOCK_TTL = 300;

    /**
     * Reservation id for the migration-map write itself. Not a real AIOVG post id;
     * negative so it can never collide with one.
     */
    const MAP_LOCK_ID = -1;

    const MAP_LOCK_ATTEMPTS = 6;

    const MAP_LOCK_WAIT_US = 25000;

    /** FluentPlayer's tag taxonomy — registered by PRO, absent on a free-only site. */
    const TARGET_TAXONOMY = 'flp_media_tag';

    /**
     * Whether tags can be carried across on this install. False on free, where Pro
     * never registered the target taxonomy.
     *
     * @return bool
     */
    public static function canMigrateTags()
    {
        return taxonomy_exists(self::SOURCE_TAXONOMY) && taxonomy_exists(self::TARGET_TAXONOMY);
    }

    /**
     * @param array $aiovgPostIds
     * @param bool  $force Re-import already-mapped videos, updating in place.
     * @return array
     */
    public static function migrate($aiovgPostIds, $force = false)
    {
        // Deduplicate: the id list comes straight from a REST payload, and a
        // repeated id would otherwise be evaluated twice against a map that had not
        // yet been updated, creating two posts for one source video.
        $aiovgPostIds = array_values(array_unique(array_filter(array_map('absint', (array) $aiovgPostIds))));

        $map = MigrationMap::get(self::SOURCE_KEY);
        if (!isset($map['media']) || !is_array($map['media'])) {
            $map['media'] = [];
        }

        if ($aiovgPostIds) {
            _prime_post_caches($aiovgPostIds, false, true);
            // Same N+1 the scanner primes away: embedded post_content URLs would
            // otherwise cost one uncached attachment query each.
            SourceResolver::primeContentAttachments($aiovgPostIds);
        }

        // One query for the whole batch instead of one per item.
        $stamped = self::findImportedBySourceIds($aiovgPostIds);

        $canMigrateTags = self::canMigrateTags();

        // Defer term counting across the batch: WP otherwise recounts every term on
        // each wp_set_object_terms call. Cache invalidation is deliberately NOT
        // suspended — this loop reads posts back after writing them, and suspension
        // would hand it stale objects.
        wp_defer_term_counting(true);

        $migrated = 0;
        $skipped = 0;
        $errors = [];
        $skippedItems = [];
        // Only the ids this batch actually resolved. Writing back the whole
        // pre-loop snapshot would clobber entries another request changed while
        // this one was running.
        $touched = [];

        foreach ($aiovgPostIds as $aiovgPostId) {
            // Map first, then the durable stamp. The stamp catches an id whose map
            // entry was lost (crashed run) or written by an overlapping request.
            $existingFpId = self::mappedId($map, $aiovgPostId);
            if (!$existingFpId || get_post_type($existingFpId) !== Media::$postType) {
                $existingFpId = isset($stamped[$aiovgPostId]) ? $stamped[$aiovgPostId] : 0;
            }

            if ($existingFpId && get_post_type($existingFpId) === Media::$postType) {
                // Repair the map even when the import itself is skipped: we only
                // reached here via the stamp because the entry was missing, and
                // leaving it missing would let the NEXT run duplicate the post.
                $map['media'][$aiovgPostId] = $existingFpId;
                $touched[$aiovgPostId] = $existingFpId;

                if (!$force) {
                    $skipped++;
                    $skippedItems[] = self::skipped($aiovgPostId, 'already_migrated');
                    continue;
                }
            } else {
                $existingFpId = 0;
            }

            $resolved = SourceResolver::resolve($aiovgPostId);

            if (!$resolved['migratable']) {
                $skipped++;
                $skippedItems[] = self::skipped($aiovgPostId, $resolved['reason']);
                continue;
            }

            // Claim the id before creating anything. Two overlapping requests can
            // otherwise both pass the map/stamp checks above and both insert.
            $lockToken = self::reserve($aiovgPostId);

            if (!$lockToken) {
                $skipped++;
                $skippedItems[] = self::skipped($aiovgPostId, 'in_progress');
                continue;
            }

            try {
                // The map is recorded from INSIDE migrateSingle the moment the post
                // exists, via this callback — several writes (settings meta,
                // thumbnail, tags) happen after wp_insert_post, and a throw in any
                // of them would otherwise orphan a post that reset can never delete
                // and a retry would duplicate.
                // Update the IN-MEMORY map only. Persisting per item meant
                // re-reading and re-serialising a growing option on every insert —
                // O(n^2) across a batch. The durable link is the post-meta stamp
                // written inside migrateSingle, so a crash before the single
                // end-of-batch write still leaves nothing unreachable.
                $recordMap = function ($fpId) use (&$map, &$touched, $aiovgPostId) {
                    $map['media'][$aiovgPostId] = (int) $fpId;
                    $touched[$aiovgPostId] = (int) $fpId;
                };

                $fpMediaId = self::migrateSingle($aiovgPostId, $resolved, $existingFpId, $canMigrateTags, $recordMap);

                if ($fpMediaId > 0) {
                    $migrated++;
                } else {
                    $skipped++;
                    $skippedItems[] = self::skipped($aiovgPostId, 'no_source');
                }
            } catch (\Throwable $e) {
                $post = get_post($aiovgPostId);
                $errors[] = [
                    'id'    => $aiovgPostId,
                    'title' => $post ? Sanitizer::sanitizeTextField($post->post_title) : "(ID: {$aiovgPostId})",
                    'error' => __('Failed to migrate this video.', 'fluent-player'),
                ];
            } finally {
                // Always release, including on the error path — otherwise a single
                // failed item blocks that video for LOCK_TTL.
                self::release($aiovgPostId, $lockToken);
            }
        }

        wp_defer_term_counting(false);

        // One write for the whole batch, merging ONLY what this batch touched.
        $mapWritten = self::persistMap($touched);

        return [
            'migrated'      => $migrated,
            'skipped'       => $skipped,
            'skipped_items' => $skippedItems,
            'errors'        => $errors,
            // The media exist and carry their source stamps; only the map index
            // is behind. Re-running the import is safe and repairs it.
            'map_deferred'  => !$mapWritten,
            'message'       => sprintf(
                /* translators: 1: number of videos successfully migrated, 2: number of videos skipped. */
                __('%1$d videos migrated, %2$d skipped.', 'fluent-player'),
                $migrated,
                $skipped
            ),
        ];
    }

    /**
     * Resolve a map entry to a post id, defensively.
     *
     * `(int)` on a non-empty array is 1 in PHP, so a corrupt map would silently
     * target whatever post owns id 1.
     *
     * @param array $map
     * @param int   $aiovgPostId
     * @return int
     */
    private static function mappedId(array $map, $aiovgPostId)
    {
        $value = isset($map['media'][$aiovgPostId]) ? $map['media'][$aiovgPostId] : 0;

        return is_scalar($value) ? absint($value) : 0;
    }

    /**
     * @param int   $aiovgPostId
     * @param array $resolved
     * @param int   $existingFpId
     * @return int FluentPlayer media post ID, or 0 when nothing was created.
     */
    private static function migrateSingle($aiovgPostId, array $resolved, $existingFpId, $canMigrateTags = false, $recordMap = null)
    {
        $post = get_post($aiovgPostId);

        if (!$post) {
            return 0;
        }

        $settings = FieldMapper::mapMediaSettings($resolved);

        if (empty($settings['src'])) {
            return 0;
        }

        // AIOVG allows untitled videos; fall back to the filename so the media
        // library does not fill up with blank rows.
        $title = $post->post_title;
        if ($title === '') {
            $title = basename((string) wp_parse_url($settings['src'], PHP_URL_PATH));
        }
        $title = Sanitizer::sanitizeTextField($title ?: __('Imported Media', 'fluent-player'));

        // aiovg_videos are ordinary public posts, so the status carries over as-is —
        // unlike Presto's pp_video_block, whose private-by-default handling exists
        // because those posts are not meant to be browsable on their own.
        $postStatus = $post->post_status;
        $postPassword = (string) $post->post_password;

        $settings['title'] = $title;
        $settings['post_status'] = $postStatus;

        $dateArgs = [];
        if ($postStatus === 'future') {
            // Without the original date WP sees a 'future' post dated in the past
            // and publishes it immediately.
            $dateArgs = [
                'post_date'     => $post->post_date,
                'post_date_gmt' => $post->post_date_gmt,
                'edit_date'     => true,
            ];
        }

        // get_post() alone is not enough: a mapped id can stop being FluentPlayer
        // media (deleted then reused, or a stale map). Without the post_type check
        // this retitles a stranger's post and rewrites its post_status — a draft
        // could be flipped to publish. deleteImported() already guards this way.
        if ($existingFpId && get_post_type($existingFpId) === Media::$postType) {
            $update = [
                'ID'          => $existingFpId,
                'post_title'  => $title,
                'post_status' => $postStatus,
            ];
            // A re-import may lock a media but never unlock one: an empty source keeps the media's own password.
            if ($postPassword !== '') {
                $update['post_password'] = $postPassword;
            }
            wp_update_post(array_merge($update, $dateArgs));
            $fpPostId = $existingFpId;

            // Re-stamp and re-register. Reaching this branch via the stamp means
            // the map entry was lost, and leaving it lost would let the NEXT run
            // duplicate the post.
            update_post_meta($fpPostId, self::SOURCE_ID_META, (int) $aiovgPostId);
            if (is_callable($recordMap)) {
                $recordMap($fpPostId);
            }
        } else {
            $fpPostId = wp_insert_post(array_merge([
                'post_type'     => Media::$postType,
                'post_title'    => $title,
                'post_status'   => $postStatus,
                'post_password' => $postPassword,
                'post_content'  => '',
            ], $dateArgs), true);

            if (is_wp_error($fpPostId)) {
                throw new \Exception(esc_html($fpPostId->get_error_message()));
            }

            // Stamp the source id BEFORE anything else can throw. This is what
            // makes the post recoverable — by a re-import and by reset — even if
            // the batch never reaches its map write.
            update_post_meta($fpPostId, self::SOURCE_ID_META, (int) $aiovgPostId);

            if (is_callable($recordMap)) {
                $recordMap($fpPostId);
            }

            // The media post renders through its own block, same as the Presto import.
            wp_update_post([
                'ID'           => $fpPostId,
                'post_content' => '<!-- wp:fluent-player/media {"mediaId":' . $fpPostId . '} /-->',
            ]);
        }

        // On an update, refresh ONLY the fields genuinely derived from AIOVG and
        // keep everything else. Replacing the whole blob destroys what the user
        // tuned after importing (branding, overlays, chapters); and even
        // array_merge is too broad, because keys like loadStrategy/preset_slug are
        // defaults this mapper invents rather than anything AIOVG supplied.
        $existingSettings = $existingFpId ? get_post_meta($fpPostId, 'settings', true) : [];

        if ($existingFpId && is_array($existingSettings) && $existingSettings) {
            $refreshed = $existingSettings;

            foreach (self::SOURCE_DERIVED_KEYS as $key) {
                if (array_key_exists($key, $settings)) {
                    $refreshed[$key] = $settings[$key];
                } else {
                    // Absent from the new mapping means the source no longer has it
                    // (e.g. the poster was removed in AIOVG).
                    unset($refreshed[$key]);
                }
            }

            $settings = $refreshed;
        }

        update_post_meta($fpPostId, 'settings', $settings);

        // AIOVG keeps its poster as a URL plus an optional attachment id; only the
        // latter can become a real featured image.
        $posterId = absint(get_post_meta($aiovgPostId, 'image_id', true));
        // Must be an ATTACHMENT: any other post type produces a broken thumbnail.
        if ($posterId && get_post_type($posterId) === 'attachment') {
            set_post_thumbnail($fpPostId, $posterId);
        }

        if ($canMigrateTags) {
            $tags = wp_get_object_terms($aiovgPostId, self::SOURCE_TAXONOMY, ['fields' => 'names']);

            if (!is_wp_error($tags) && $tags) {
                // By NAME, not id: wp_set_object_terms reuses an existing term of the
                // same name and creates it otherwise, so two videos sharing a tag end
                // up on one term instead of splitting the library. Not appended, so a
                // forced re-import cannot accumulate duplicates.
                // Append on an update so tags the user added post-import survive;
                // replace on a first import so there is nothing stale to keep.
                wp_set_object_terms($fpPostId, $tags, self::TARGET_TAXONOMY, (bool) $existingFpId);
            }
        }

        return (int) $fpPostId;
    }

    /**
     * Delete everything this source imported. Used by the migration reset.
     *
     * @return int
     */
    public static function deleteImported()
    {
        $deleted = 0;
        $mapped = (array) MigrationMap::bucket(self::SOURCE_KEY, 'media', []);

        // One query instead of one per id, matching the Presto reset.
        $ids = array_filter(array_map('absint', array_values($mapped)));
        if ($ids) {
            _prime_post_caches($ids, false, false);
        }

        $targets = [];

        foreach ($mapped as $fpId) {
            $fpId = absint($fpId);

            // Only ever delete posts that are still FluentPlayer media — the id
            // could have been recycled after a manual delete.
            if ($fpId && get_post_type($fpId) === Media::$postType) {
                $targets[$fpId] = true;
            }
        }

        // Also sweep anything carrying our stamp. A run that died before its map
        // write would otherwise leave media that reset could never reach.
        foreach (self::findAllImported() as $fpId) {
            $targets[$fpId] = true;
        }

        foreach (array_keys($targets) as $fpId) {
            wp_delete_post($fpId, true);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param int $aiovgPostId
     * @return string
     */
    public static function lockKey($aiovgPostId)
    {
        return '_flp_aiovg_import_lock_' . (int) $aiovgPostId;
    }

    /**
     * Atomically reserve a source id before creating its media.
     *
     * Checking the map or the source stamp first is not enough: two overlapping
     * requests can both observe "not imported" and both insert. `INSERT IGNORE`
     * against the unique `option_name` index is the same primitive WP core uses
     * for its upgrader lock (class-wp-upgrader.php:1065) — the loser gets 0 rows.
     *
     * Returns an ownership token rather than a bare true: release() verifies it,
     * so a caller whose reservation was taken over as stale cannot free whoever
     * owns it now.
     *
     * @param int $aiovgPostId
     * @return string|false Ownership token, or false when another caller holds it.
     */
    public static function reserve($aiovgPostId)
    {
        global $wpdb;

        $key = self::lockKey($aiovgPostId);
        $token = self::newToken();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic reservation; get_option/add_option cannot express this without a race.
        $acquired = (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
            $key,
            $token
        ));

        wp_cache_delete($key, 'options');

        if ($acquired) {
            return $token;
        }

        // Someone holds it. Take over only if their reservation has expired --
        // otherwise a process that died mid-import would block this video forever.
        $held = (string) get_option($key, '');

        if ($held === '' || !self::isExpired($held)) {
            return false;
        }

        // Compare-and-swap on the exact stale value. Deleting and re-inserting
        // would let two callers that both saw the same stale lock each believe
        // they won, and would also delete a reservation a third caller had just
        // taken over in between.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic takeover of an expired reservation.
        $swapped = (int) $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
            $token,
            $key,
            $held
        ));

        wp_cache_delete($key, 'options');

        return $swapped ? $token : false;
    }

    /**
     * Ownership token for a reservation: when it was taken, plus enough entropy
     * that no two callers can ever present the same one.
     *
     * @return string
     */
    private static function newToken()
    {
        return time() . ':' . wp_generate_password(12, false, false);
    }

    /**
     * @param string $token
     * @return bool
     */
    private static function isExpired($token)
    {
        $parts = explode(':', (string) $token, 2);
        $heldSince = (int) $parts[0];

        return $heldSince > 0 && (time() - $heldSince) > self::LOCK_TTL;
    }

    /**
     * Release a reservation -- but only if this caller still owns it.
     *
     * An unconditional delete lets a caller whose reservation was taken over as
     * stale come back and free the CURRENT owner, reopening the duplicate window
     * the reservation exists to close.
     *
     * @param int    $aiovgPostId
     * @param string $token Token returned by reserve().
     * @return bool True when this call actually freed the reservation.
     */
    public static function release($aiovgPostId, $token)
    {
        global $wpdb;

        $key = self::lockKey($aiovgPostId);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership-checked release; delete_option() cannot express the condition.
        $deleted = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s",
            $key,
            (string) $token
        ));

        wp_cache_delete($key, 'options');

        return $deleted > 0;
    }

    /**
     * Merge this batch's mappings into the stored map and write once.
     *
     * Re-reads immediately before writing and applies ONLY the ids this batch
     * resolved. Writing back the snapshot taken before the loop would silently
     * revert any entry a concurrent import changed in the meantime.
     *
     * @param array<int,int> $touched sourceId => FluentPlayer media id
     * @return bool True when the map was written; false when the lock never came free.
     */
    private static function persistMap(array $touched)
    {
        if (!$touched) {
            return true;
        }

        // Read-modify-write on an option is not atomic: two batches finishing at
        // once can interleave between the read and the write, and the later writer
        // drops the earlier one's entries. Serialise the whole sequence behind the
        // same INSERT IGNORE primitive used to reserve source ids.
        //
        // Acquiring and then merging regardless of the result would make the lock
        // decorative. Wait with exponential backoff instead, and if the lock never
        // comes free, do NOT write: a blind merge can discard a concurrent batch's
        // mappings. The caller is told so it can retry, and re-running an import is
        // safe because every imported media carries its source stamp.
        $token = false;
        $wait = self::MAP_LOCK_WAIT_US;

        for ($attempt = 0; $attempt < self::MAP_LOCK_ATTEMPTS; $attempt++) {
            $token = self::reserve(self::MAP_LOCK_ID);

            if ($token) {
                break;
            }

            if ($attempt < self::MAP_LOCK_ATTEMPTS - 1) {
                usleep($wait);
                $wait *= 2;
            }
        }

        if (!$token) {
            return false;
        }

        try {
            $stored = MigrationMap::get(self::SOURCE_KEY);
            $stored['media'] = isset($stored['media']) && is_array($stored['media'])
                ? array_replace($stored['media'], $touched)
                : $touched;

            MigrationMap::put(self::SOURCE_KEY, $stored);
        } finally {
            self::release(self::MAP_LOCK_ID, $token);
        }

        return true;
    }

    /**
     * Find media already imported from a given AIOVG post, via the durable stamp.
     *
     * @param int $aiovgPostId
     * @return int
     */
    /**
     * Bulk stamp lookup: sourceId => FluentPlayer media id, for the given ids.
     *
     * @param int[] $aiovgPostIds
     * @return array<int,int>
     */
    public static function findImportedBySourceIds(array $aiovgPostIds)
    {
        global $wpdb;

        $aiovgPostIds = array_values(array_unique(array_filter(array_map('absint', $aiovgPostIds))));

        if (!$aiovgPostIds) {
            return [];
        }

        // One query for the whole batch. get_posts + get_post_meta per hit would
        // reintroduce the N+1 this replaces.
        $placeholders = implode(',', array_fill(0, count($aiovgPostIds), '%d'));
        $params = array_merge([self::SOURCE_ID_META], $aiovgPostIds, [Media::$postType]);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch stamp lookup; placeholders are generated %d, every value is bound.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS source_id, pm.post_id AS fp_id
             FROM `{$wpdb->postmeta}` pm
             INNER JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value IN ({$placeholders})
               AND p.post_type = %s
             ORDER BY pm.post_id ASC",
            $params
        ));

        $map = [];
        foreach ((array) $rows as $row) {
            $sourceId = absint($row->source_id);
            // First stamp wins: a duplicate left by an older crash must not
            // displace the one already recorded.
            if ($sourceId && !isset($map[$sourceId])) {
                $map[$sourceId] = (int) $row->fp_id;
            }
        }

        return $map;
    }

    public static function findImportedBySourceId($aiovgPostId)
    {
        $found = self::findImportedBySourceIds([$aiovgPostId]);

        return isset($found[(int) $aiovgPostId]) ? $found[(int) $aiovgPostId] : 0;
    }

    /**
     * Clear the map without touching the imported posts.
     *
     * @return void
     */
    public static function forgetMapOnly()
    {
        MigrationMap::forget(self::SOURCE_KEY);
    }

    /**
     * Every media carrying the AIOVG source stamp, mapped or not.
     *
     * @return int[]
     */
    private static function findAllImported()
    {
        $found = get_posts([
            'post_type'        => Media::$postType,
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_key'         => self::SOURCE_ID_META,
        ]);

        return array_map('intval', (array) $found);
    }

    /**
     * @param int    $aiovgPostId
     * @param string $reason
     * @return array
     */
    private static function skipped($aiovgPostId, $reason)
    {
        $post = get_post($aiovgPostId);

        return [
            'id'     => (int) $aiovgPostId,
            'title'  => $post ? Sanitizer::sanitizeTextField($post->post_title) : "(ID: {$aiovgPostId})",
            'reason' => $reason,
        ];
    }
}
