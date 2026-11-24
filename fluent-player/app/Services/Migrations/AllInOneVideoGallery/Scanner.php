<?php

namespace FluentPlayer\App\Services\Migrations\AllInOneVideoGallery;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\Migrations\AllInOneVideoGallery\MediaMigrator;
use FluentPlayer\App\Services\Migrations\MigrationMap;
use FluentPlayer\Framework\Support\Sanitizer;

/**
 * Produces the numbers on the All-in-One Video Gallery card: how many videos can
 * be imported, split by resolved provider, and an honest tally of what cannot.
 *
 * Counts come from SourceResolver rather than from AIOVG's `type` meta, because
 * `type` alone does not say whether a row actually has a playable source — the
 * three videos on the reporting site are all type=default with every source field
 * blank.
 */
class Scanner
{
    const POST_TYPE = 'aiovg_videos';

    /** Providers a resolved AIOVG video can map onto. */
    const PROVIDERS = ['wordpress', 'external', 'youtube', 'vimeo', 'bunny'];

    /**
     * Ceiling on rows examined per request, matching the Presto scanner's own caps
     * (PrestoPlayer/Scanner.php:417). AIOVG libraries routinely hold thousands of
     * videos, and detect()/scan() resolve every row.
     */
    const MAX_VIDEOS = 5000;

    /** @var int|null Per-request memo for videoCount(). */
    private static $videoCountMemo = null;

    private static $importedCountMemo = null;

    /**
     * Test seam — the memo would otherwise outlive a per-test database refresh.
     *
     * @return void
     */
    public static function flushCache()
    {
        self::$videoCountMemo = null;
        self::$importedCountMemo = null;
        SourceResolver::flushCache();
    }

    /**
     * Whether AIOVG's own plugin is running (as opposed to leftover data).
     *
     * @return bool
     */
    public static function isPluginActive()
    {
        return defined('AIOVG_PLUGIN_VERSION');
    }

    /**
     * @return string
     */
    public static function version()
    {
        if (defined('AIOVG_PLUGIN_VERSION')) {
            return (string) AIOVG_PLUGIN_VERSION;
        }

        return '';
    }

    /**
     * Cheap existence probe for SourceRegistry::detected() — a single COUNT, no
     * per-row resolution, because it runs for every source on every request.
     *
     * @return int
     */
    public static function videoCount()
    {
        // Memoised per REQUEST, not cached across requests. This gates the
        // Migration nav item and is called several times per page load (once per
        // source through SourceRegistry::detected(), again via isAvailable()), so
        // the repeat queries are worth removing. A persistent cache is deliberately
        // avoided: it would need invalidation hooks on every aiovg_videos write,
        // and a stale nav item is worse than one indexed COUNT per page.
        if (self::$videoCountMemo === null) {
            self::$videoCountMemo = self::queryVideoCount();
        }

        return self::$videoCountMemo;
    }

    /**
     * @return int
     */
    private static function queryVideoCount()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scanner: one-shot count against $wpdb->posts for the card; caching would mask newly added videos.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft')",
            self::POST_TYPE
        ));
    }

    /**
     * Video IDs in a stable order (newest first, matching AIOVG's own list table).
     *
     * @return int[]
     */
    public static function videoIds($limit = null)
    {
        $limit = $limit === null ? self::MAX_VIDEOS : (int) $limit;

        // ONE budget across both halves. Listing every imported id on top of the
        // capped window let the scanned set grow without bound as a library was
        // migrated, so detect()/scan() quietly exceeded the ceiling they advertise.
        //
        // Unimported ids come first because they are what the user can still act
        // on; imported ids fill whatever budget is left, purely so the selector can
        // show them as already done. The card's history counts come from
        // migrationHistory(), not from this list, so capping here cannot understate
        // what was imported.
        $unimported = self::unimportedVideoIds($limit);
        $remaining = max(0, $limit - count($unimported));
        $imported = $remaining > 0 ? self::importedSourceIds($remaining) : [];

        $ids = array_values(array_unique(array_merge($imported, $unimported)));

        rsort($ids);

        return $ids;
    }

    /**
     * AIOVG post ids that have NOT yet been imported, newest first.
     *
     * @param int|null $limit
     * @return int[]
     */
    public static function unimportedVideoIds($limit = null)
    {
        global $wpdb;

        $limit = $limit === null ? self::MAX_VIDEOS : (int) $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scanner: one-shot id list against $wpdb->posts.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM `{$wpdb->posts}` p
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND NOT EXISTS (
                   SELECT 1 FROM `{$wpdb->postmeta}` pm
                   WHERE pm.meta_key = %s AND pm.meta_value = p.ID
               )
             ORDER BY p.ID DESC
             LIMIT %d",
            self::POST_TYPE,
            MediaMigrator::SOURCE_ID_META,
            $limit
        ));

        return array_map('intval', (array) $ids);
    }

    /**
     * AIOVG post ids that already have imported media, newest first.
     *
     * @return int[]
     */
    public static function importedSourceIds($limit = null)
    {
        global $wpdb;

        // null or 0 means unbounded; videoIds() passes whatever budget is left.
        $limit = $limit === null ? 0 : max(0, (int) $limit);

        $sql = "SELECT DISTINCT p.ID FROM `{$wpdb->posts}` p
             INNER JOIN `{$wpdb->postmeta}` pm ON pm.meta_value = p.ID AND pm.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
             ORDER BY p.ID DESC";

        $params = [MediaMigrator::SOURCE_ID_META, self::POST_TYPE];

        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $params[] = $limit;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scanner: one-shot id list; every value is bound.
        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        return array_map('intval', (array) $ids);
    }

    /**
     * @return array
     */
    public static function detect()
    {
        $ids = self::videoIds();

        if (!$ids) {
            return [
                'detected'       => false,
                'active'         => self::isPluginActive(),
                'version'        => self::version(),
                'has_pro'        => Helper::hasPro(),
                'media'          => ['total' => 0, 'by_provider' => self::emptyProviderTally()],
                'unmigratable'   => self::emptyReasonTally(),
                'tags'           => self::tagSummary(),
                'migration_history' => self::migrationHistory([]),
                'truncated'      => false,
                'scan_limit'     => self::MAX_VIDEOS,
                'cannot_migrate' => [],
                'message'        => __('No All-in-One Video Gallery videos found.', 'fluent-player'),
            ];
        }

        _prime_post_caches($ids, false, true);
        self::primeAttachments($ids);

        $byProvider = self::emptyProviderTally();
        $unmigratable = self::emptyReasonTally();
        $unsupportedTypes = [];
        $migratableIds = [];
        $total = 0;

        foreach ($ids as $id) {
            $resolved = SourceResolver::resolve($id);

            if (!$resolved['migratable']) {
                $reason = $resolved['reason'];
                if (isset($unmigratable[$reason])) {
                    $unmigratable[$reason]++;
                }
                if ($reason === 'unsupported_provider' && $resolved['aiovg_type']) {
                    $unsupportedTypes[$resolved['aiovg_type']] = true;
                }
                continue;
            }

            $total++;
            $migratableIds[] = $id;
            $provider = $resolved['provider'];
            if (isset($byProvider[$provider])) {
                $byProvider[$provider]++;
            }
        }

        // Computed once — cannotMigrateNotes() needs it too, and it was previously
        // recomputed per call.
        $tagSummary = self::tagSummary();

        return [
            'detected'       => true,
            'active'         => self::isPluginActive(),
            'version'        => self::version(),
            'has_pro'        => Helper::hasPro(),
            'media'          => ['total' => $total, 'by_provider' => $byProvider],
            'unmigratable'   => $unmigratable,
            'tags'           => $tagSummary,
            'migration_history' => self::migrationHistory($migratableIds),
            // Say so rather than presenting a partial analysis as the whole library.
            'truncated'      => count(self::unimportedVideoIds(self::MAX_VIDEOS)) >= self::MAX_VIDEOS,
            'scan_limit'     => self::MAX_VIDEOS,
            'cannot_migrate' => self::cannotMigrateNotes(array_keys($unsupportedTypes), $unmigratable, $tagSummary),
            'message'        => '',
        ];
    }

    /**
     * Itemised list for the review step.
     *
     * @param string $provider Filter by resolved provider, or 'all'.
     * @param string $search   Title substring.
     * @return array
     */
    public static function scan($provider = 'all', $search = '')
    {
        $ids = self::videoIds();

        if (!$ids) {
            return ['media' => []];
        }

        _prime_post_caches($ids, false, true);
        self::primeAttachments($ids);

        // One query for every video's tags instead of one per video. Unlike
        // wp_get_object_terms(), get_the_terms() DOES read this cache.
        if (taxonomy_exists(MediaMigrator::SOURCE_TAXONOMY)) {
            update_object_term_cache($ids, self::POST_TYPE);
        }

        $provider = sanitize_key($provider);
        $search = trim((string) $search);
        $imported = self::importedMapFor($ids);
        $media = [];

        foreach ($ids as $id) {
            $resolved = SourceResolver::resolve($id);
            $post = get_post($id);

            if (!$post) {
                continue;
            }

            if ($provider !== '' && $provider !== 'all' && $resolved['provider'] !== $provider) {
                continue;
            }

            if ($search !== '' && stripos($post->post_title, $search) === false) {
                continue;
            }

            $media[] = [
                'id'            => (int) $id,
                'title'         => Sanitizer::sanitizeTextField($post->post_title),
                'post_status'   => $post->post_status,
                'provider'      => $resolved['provider'],
                // Escaped at the API boundary like `title` two lines up, so a
                // future consumer that binds this into an href cannot be surprised.
                'src'           => Sanitizer::escUrlRaw($resolved['src']),
                'mediaType'     => $resolved['mediaType'],
                'attachment_id' => $resolved['attachment_id'],
                'duration'      => $resolved['duration'],
                'migratable'    => $resolved['migratable'],
                'reason'        => $resolved['reason'],
                'dropped'       => $resolved['dropped'],
                'tags'          => self::tagNamesFor($id),
                // Lets the card avoid re-importing by default and lets the selector
                // tag the row, exactly as the Presto selector does.
                'imported'      => isset($imported[$id]),
                'imported_id'   => isset($imported[$id]) ? $imported[$id] : 0,
            ];
        }

        return ['media' => $media];
    }

    /**
     * @param int $postId
     * @return string[]
     */
    private static function tagNamesFor($postId)
    {
        if (!taxonomy_exists(MediaMigrator::SOURCE_TAXONOMY)) {
            return [];
        }

        // get_the_terms() reads the object term cache primed above;
        // wp_get_object_terms() would query per post.
        $terms = get_the_terms($postId, MediaMigrator::SOURCE_TAXONOMY);

        if (is_wp_error($terms) || !$terms) {
            return [];
        }

        return array_values(wp_list_pluck($terms, 'name'));
    }

    /**
     * SourceResolver reads wp_get_attachment_metadata() for attachment-backed rows.
     * Those attachment IDs are not in the video ID list, so without priming them the
     * loop costs one uncached meta query per video.
     *
     * @param int[] $ids
     * @return void
     */
    private static function primeAttachments(array $ids)
    {
        // Embedded post_content URLs resolve to attachments one uncached query at
        // a time otherwise — thousands of them across a full scan window.
        SourceResolver::primeContentAttachments($ids);

        $attachmentIds = [];

        foreach ($ids as $id) {
            foreach (['mp4_id', 'webm_id', 'ogv_id'] as $key) {
                $attachmentId = absint(get_post_meta($id, $key, true));
                if ($attachmentId) {
                    $attachmentIds[$attachmentId] = true;
                }
            }
        }

        if ($attachmentIds) {
            _prime_post_caches(array_keys($attachmentIds), false, true);
        }
    }

    /**
     * How many AIOVG tags exist, and whether this install can receive them —
     * FluentPlayer's tag taxonomy is registered by Pro.
     *
     * @return array{supported: bool, total: int}
     */
    public static function tagSummary()
    {
        if (!taxonomy_exists(MediaMigrator::SOURCE_TAXONOMY)) {
            return ['supported' => false, 'total' => 0];
        }

        // wp_count_terms, not get_terms(fields=ids)->count(): the latter loads every
        // term id purely to count them.
        $count = wp_count_terms([
            'taxonomy'   => MediaMigrator::SOURCE_TAXONOMY,
            'hide_empty' => true,
        ]);

        return [
            'supported' => MediaMigrator::canMigrateTags(),
            'total'     => is_wp_error($count) ? 0 : (int) $count,
        ];
    }

    /**
     * Source-id => FluentPlayer-id, for the given source ids only.
     *
     * Resolved through the durable source stamp joined to posts, so media that has
     * since been deleted drops out — a stale entry would claim the video is
     * imported and leave the card no way to bring it back.
     *
     * @param int[] $sourceIds
     * @return array<int,int>
     */
    public static function importedMapFor(array $sourceIds)
    {
        $sourceIds = array_values(array_filter(array_map('absint', $sourceIds)));

        if (!$sourceIds) {
            return [];
        }

        // One joined query bounded to the ids asked about. Loading the whole map,
        // priming every post in it and iterating all of it made this scale with the
        // size of the import history rather than with the request.
        return MediaMigrator::findImportedBySourceIds($sourceIds);
    }

    /**
     * How many AIOVG videos have been imported, in total.
     *
     * A COUNT, not a map: the card shows a number, and materialising thousands of
     * pairs to call count() on them is the unbounded work this avoids.
     *
     * @return int
     */
    public static function importedCount()
    {
        global $wpdb;

        if (self::$importedCountMemo !== null) {
            return self::$importedCountMemo;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scanner: aggregate count.
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM `{$wpdb->posts}` p
             INNER JOIN `{$wpdb->postmeta}` pm ON pm.meta_value = p.ID AND pm.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')",
            MediaMigrator::SOURCE_ID_META,
            self::POST_TYPE
        ));

        return self::$importedCountMemo = $count;
    }

    /**
     * What a previous run imported — the payload behind the card's
     * "Previously Imported from All-in-One Video Gallery" state.
     *
     * @param int[] $migratableIds Ids currently eligible for import.
     * @return array
     */
    private static function migrationHistory(array $migratableIds)
    {
        // Bounded to the ids in this scan window: media_map exists so the selector
        // can mark those rows as already imported, and nothing reads entries for
        // videos outside the window. The totals come from a COUNT instead.
        $imported = self::importedMapFor($migratableIds);
        $total = self::importedCount();

        return [
            'has_history'    => $total > 0,
            'media'          => $total,
            'media_map'      => $imported,
            // How many eligible videos in this window have appeared since the last
            // import.
            'unmapped_media' => count(array_diff($migratableIds, array_keys($imported))),
        ];
    }

    /**
     * Plain-language notes for the card's "Cannot migrate" section, listing only
     * what this site actually has rather than every theoretical limitation.
     *
     * @param string[] $unsupportedTypes
     * @param array    $unmigratable
     * @param array{supported: bool, total: int}|null $tags Null reads the tag summary itself.
     * @return string[]
     */
    private static function cannotMigrateNotes(array $unsupportedTypes, array $unmigratable, ?array $tags = null)
    {
        $labels = [
            'dailymotion' => __('Dailymotion videos', 'fluent-player'),
            'rumble'      => __('Rumble videos', 'fluent-player'),
            'facebook'    => __('Facebook videos', 'fluent-player'),
            'embedcode'   => __('Embed-code videos', 'fluent-player'),
        ];

        $notes = [];

        foreach ($unsupportedTypes as $type) {
            if (isset($labels[$type])) {
                $notes[] = $labels[$type];
            }
        }

        if (!empty($unmigratable['no_source'])) {
            $notes[] = __('Videos with no media source', 'fluent-player');
        }

        // Presto's importer skips tags silently when the taxonomy is missing. Saying
        // so lets the user find out before the import rather than afterwards.
        $tags = $tags === null ? self::tagSummary() : $tags;
        if ($tags['total'] > 0 && !$tags['supported']) {
            $notes[] = __('Video tags (requires FluentPlayer Pro)', 'fluent-player');
        }

        // Always-true limitations for this slice, so the card never implies more
        // than it imports.
        $notes[] = __('Gallery shortcodes and categories', 'fluent-player');
        $notes[] = __('Views, likes and dislikes', 'fluent-player');

        return $notes;
    }

    /**
     * @return array<string,int>
     */
    private static function emptyProviderTally()
    {
        return array_fill_keys(self::PROVIDERS, 0);
    }

    /**
     * Only integer members — the card sums these to reconcile against the total.
     *
     * @return array<string,int>
     */
    private static function emptyReasonTally()
    {
        return [
            'unsupported_provider' => 0,
            'no_source'            => 0,
        ];
    }
}
