<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class Scanner
{
    const MAP_OPTION = '_fluent_player_migration_map';
    const SETTINGS_BACKUP_OPTION = '_fluent_player_settings_pre_migration';

    /**
     * Presto Player media block names we can migrate
     */
    private static $mediaBlocks = [
        'presto-player/self-hosted',
        'presto-player/youtube',
        'presto-player/vimeo',
        'presto-player/audio',
        'presto-player/bunny',
    ];

    /**
     * Detect if Presto Player data exists in the database
     *
     * @return array
     */
    public static function detect()
    {
        $isActive = defined('PRESTO_PLAYER_PLUGIN_FILE');

        if (!$isActive && !self::prestoTablesExist()) {
            return [
                'detected' => false,
                'active'   => false,
                'message'  => __('No Presto Player data found.', 'fluent-player'),
            ];
        }

        if (!$isActive) {
            return [
                'detected' => true,
                'active'   => false,
                'message'  => __('Presto Player is installed but not active. Please activate it to migrate.', 'fluent-player'),
            ];
        }

        return [
            'detected'          => true,
            'active'            => true,
            'has_pro'           => defined('PRESTO_PLAYER_PRO_PLUGIN_FILE'),
            'version'           => self::getPrestoVersion(),
            'media'             => self::getMediaCounts(),
            'presets'           => self::getPresetCounts(),
            'visits'            => self::getVisitCount(),
            'playlists'         => self::getPlaylistCount(),
            'email_submissions' => EmailSubmissionMigrator::count(),
            'affected_posts'    => self::getAffectedPostCount(),
        ];
    }

    /**
     * Scan Presto Player data with optional filters
     *
     * @param string $type
     * @param string $search
     * @return array
     */
    public static function scan($type = 'all', $search = '')
    {
        $presets = self::scanPresets();
        $presets = array_map(function ($p) {
            unset($p['raw']);
            return $p;
        }, $presets);

        return [
            'media'          => self::scanMedia($type, $search),
            'presets'        => $presets,
            'playlists'      => self::scanPlaylists(),
            'affected_posts' => self::scanAffectedPosts(),
        ];
    }

    /**
     * Scan video presets from Presto Player
     *
     * @return array
     */
    public static function scanPresets()
    {
        global $wpdb;

        $presets = [];

        foreach (['presto_player_presets' => false] as $table => $isAudio) {
            $fullTable = $wpdb->prefix . $table;
            if (!self::tableExists($fullTable)) {
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration scanner: $fullTable from $wpdb->prefix + literal suffix; one-shot read of Presto presets table; caching not appropriate.
            $rows = $wpdb->get_results("SELECT * FROM `{$fullTable}` WHERE deleted_at IS NULL");

            foreach ($rows as $row) {
                $presets[] = [
                    'id'       => (int) $row->id,
                    'name'     => Sanitizer::sanitizeTextField($row->name ?? ''),
                    'slug'     => Sanitizer::sanitizeKey($row->slug ?? ''),
                    'type'     => $isAudio ? 'audio' : 'video',
                    'is_audio' => $isAudio,
                    'raw'      => $row,
                ];
            }
        }

        return $presets;
    }

    /**
     * Find the first Presto Player media block from post content
     *
     * @param string $content
     * @return array|null [blockName, attrs]
     */
    public static function findMediaBlock($content)
    {
        $blocks = parse_blocks($content);
        return self::findMediaBlockRecursive($blocks);
    }

    /**
     * Detect provider type from post_content block markup
     *
     * @param string $content
     * @return string
     */
    private static function detectProviderFromContent($content)
    {
        $providerMap = [
            'wp:presto-player/youtube'      => 'youtube',
            'wp:presto-player/vimeo'        => 'vimeo',
            'wp:presto-player/audio'        => 'audio',
            'wp:presto-player/bunny'        => 'bunny',
            'wp:presto-player/self-hosted'  => 'self_hosted',
            'wp:presto-player/reusable-edit' => 'self_hosted',
        ];

        foreach ($providerMap as $needle => $provider) {
            if (strpos($content, $needle) !== false) {
                return $provider;
            }
        }

        return 'other';
    }

    /**
     * Check if Migration tab should be visible in admin settings.
     *
     * @return bool
     */
    public static function shouldShow()
    {
        return get_option(self::MAP_OPTION) || defined('PRESTO_PLAYER_PLUGIN_FILE');
    }

    private static function prestoTablesExist()
    {
        global $wpdb;
        return self::tableExists($wpdb->prefix . 'presto_player_presets')
            || EmailSubmissionMigrator::count() > 0;
    }

    private static $tableCache = [];

    public static function tableExists($table)
    {
        if (isset(self::$tableCache[$table])) {
            return self::$tableCache[$table];
        }

        global $wpdb;
        self::$tableCache[$table] = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return self::$tableCache[$table];
    }

    private static function getPrestoVersion()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach (get_plugins() as $path => $info) {
            if (strpos($path, 'presto-player') !== false) {
                return Arr::get($info, 'Version', '');
            }
        }

        return '';
    }

    private static function getMediaCounts()
    {
        global $wpdb;

        $baseWhere = "WHERE post_type = 'pp_video_block' AND post_status IN ('publish', 'draft', 'private', 'future', 'trash')"
            . " AND post_content LIKE '%wp:presto-player/%'"
            . " AND post_content NOT LIKE '%\"visibility\":\"private\"%'"
            . " AND post_content NOT LIKE '%\"visibility\": \"private\"%'";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: $wpdb->posts is core, $baseWhere is built internally, LIKE patterns are hardcoded block markers (no user input), one-shot scanner read.
        $providerCounts = $wpdb->get_results(
            "SELECT
                SUM(post_content LIKE '%wp:presto-player/youtube%') AS youtube,
                SUM(post_content LIKE '%wp:presto-player/vimeo%') AS vimeo,
                SUM(post_content LIKE '%wp:presto-player/audio%') AS audio,
                SUM(post_content LIKE '%wp:presto-player/bunny%') AS bunny,
                SUM(post_content LIKE '%wp:presto-player/self-hosted%') AS self_hosted
             FROM `{$wpdb->posts}` {$baseWhere}"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

        $row = $providerCounts[0] ?? null;
        $youtube = (int) ($row->youtube ?? 0);
        $selfHosted = (int) ($row->self_hosted ?? 0);
        $vimeo = (int) ($row->vimeo ?? 0);
        $audio = (int) ($row->audio ?? 0);
        $bunny = (int) ($row->bunny ?? 0);

        return [
            'total'       => $youtube + $selfHosted + $vimeo + $audio + $bunny,
            'youtube'     => $youtube,
            'self_hosted' => $selfHosted,
            'vimeo'       => $vimeo,
            'audio'       => $audio,
            'bunny'       => $bunny,
        ];
    }

    private static function getPresetCounts()
    {
        global $wpdb;

        $video = 0;

        $presetsTable = $wpdb->prefix . 'presto_player_presets';
        if (self::tableExists($presetsTable)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name from $wpdb->prefix + hardcoded string
            $video = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$presetsTable}` WHERE deleted_at IS NULL");
        }

        return ['total' => $video, 'video' => $video, 'audio' => 0];
    }

    private static function getVisitCount()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'presto_player_visits';
        if (!self::tableExists($table)) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name from $wpdb->prefix + hardcoded string
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted_at IS NULL");
    }

    private static function getAffectedPostCount()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name from $wpdb->prefix + hardcoded string
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT ID) FROM `{$wpdb->posts}`
             WHERE post_type NOT IN ('pp_video_block', 'revision', 'nav_menu_item')
             AND post_status IN ('publish', 'draft', 'private', 'pending')
             AND (
                 post_content LIKE '%<!-- wp:presto-player/%'
                 OR post_content LIKE '%[presto_player %'
                 OR post_content LIKE '%[presto_player]%'
                 OR post_content LIKE '%[presto_timestamp %'
                 OR post_content LIKE '%[pptime %'
                 OR post_content LIKE '%[presto_playlist%'
                 OR post_content LIKE '%[presto_popup %'
             )"
        );
    }

    private static function getPlaylistCount()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name from $wpdb->prefix + hardcoded string
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT ID) FROM `{$wpdb->posts}`
             WHERE post_type NOT IN ('pp_video_block', 'revision', 'nav_menu_item')
             AND post_status IN ('publish', 'draft', 'private', 'pending')
             AND (
                 post_content LIKE '%<!-- wp:presto-player/playlist%'
                 OR post_content LIKE '%[presto_playlist%'
             )"
        );
    }

    /**
     * Scan posts containing PP playlists and extract playlist data
     *
     * @return array
     */
    public static function scanPlaylists()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name from $wpdb->prefix + hardcoded string
        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_content, post_status
             FROM `{$wpdb->posts}`
             WHERE post_type NOT IN ('pp_video_block', 'revision', 'nav_menu_item')
             AND post_status IN ('publish', 'draft', 'private', 'pending')
             AND (
                 post_content LIKE '%<!-- wp:presto-player/playlist%'
                 OR post_content LIKE '%[presto_playlist%'
             )
             ORDER BY ID DESC
             LIMIT 500"
        );

        $playlists = [];
        foreach ($posts as $post) {
            $blocks = parse_blocks($post->post_content);
            $playlistBlocks = self::findPlaylistBlocks($blocks);

            foreach ($playlistBlocks as $index => $block) {
                $attrs = Arr::get($block, 'attrs', []);
                $itemIds = self::extractPlaylistItemIds($block);

                $playlists[] = [
                    'post_id'    => (int) $post->ID,
                    'post_title' => Sanitizer::sanitizeTextField($post->post_title ?: __('(Untitled)', 'fluent-player')),
                    'index'      => $index,
                    'heading'    => Sanitizer::sanitizeTextField(Arr::get($attrs, 'heading', 'Playlist')),
                    'item_count' => count($itemIds),
                    'item_ids'   => $itemIds,
                    'attrs'      => $attrs,
                ];
            }
        }

        return $playlists;
    }

    /**
     * Find all presto-player/playlist blocks in a block tree
     */
    private static function findPlaylistBlocks($blocks)
    {
        $found = [];

        foreach ($blocks as $block) {
            if (Arr::get($block, 'blockName', '') === 'presto-player/playlist') {
                $found[] = $block;
            }

            if (!empty($block['innerBlocks'])) {
                $found = array_merge($found, self::findPlaylistBlocks($block['innerBlocks']));
            }
        }

        return $found;
    }

    /**
     * Extract pp_video_block post IDs from playlist inner blocks
     */
    private static function extractPlaylistItemIds($playlistBlock)
    {
        $ids = [];

        foreach (Arr::get($playlistBlock, 'innerBlocks', []) as $innerBlock) {
            $blockName = Arr::get($innerBlock, 'blockName', '');

            if (in_array($blockName, ['presto-player/reusable-display', 'presto-player/reusable', 'presto-player/playlist-list-item'], true)) {
                $id = Arr::get($innerBlock, 'attrs.id', 0);
                if ($id) {
                    $ids[] = (int) $id;
                }
            }

            if (!empty($innerBlock['innerBlocks'])) {
                $nestedIds = self::extractPlaylistItemIds($innerBlock);
                $ids = array_merge($ids, $nestedIds);
            }
        }

        return array_unique($ids);
    }

    private static function scanMedia($type, $search)
    {
        global $wpdb;

        $where = "WHERE post_type = 'pp_video_block' AND post_status IN ('publish', 'draft', 'private', 'future', 'trash')";

        if ($search) {
            $where .= $wpdb->prepare(" AND post_title LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: $wpdb->posts is core; $where is built internally (search part already prepared with $wpdb->prepare + esc_like above); one-shot scanner read for migration UI.
        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_content, post_status, post_date
             FROM `{$wpdb->posts}` {$where} ORDER BY ID DESC LIMIT 5000"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

        $items = [];
        foreach ($posts as $post) {
            $provider = self::detectProviderFromContent($post->post_content);

            if ($provider === 'other') {
                continue;
            }

            if ($type !== 'all' && $provider !== $type) {
                continue;
            }

            $block = self::findMediaBlock($post->post_content);
            if (!$block) {
                continue;
            }

            $attrs = Arr::get($block, 'attrs', []);

            if (self::isProtectedMedia($attrs)) {
                continue;
            }

            $items[] = [
                'id'       => (int) $post->ID,
                'title'    => Sanitizer::sanitizeTextField($post->post_title ?: __('(Untitled)', 'fluent-player')),
                'provider' => $provider,
                'src'      => Sanitizer::escUrlRaw(Arr::get($attrs, 'src', '')),
                'poster'   => Sanitizer::escUrlRaw(Arr::get($attrs, 'poster', '')),
                'status'   => $post->post_status,
                'date'     => $post->post_date,
            ];
        }

        return $items;
    }

    private static function scanAffectedPosts()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name from $wpdb->prefix + hardcoded string
        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_type, post_status
             FROM `{$wpdb->posts}`
             WHERE post_type NOT IN ('pp_video_block', 'revision', 'nav_menu_item')
             AND post_status IN ('publish', 'draft', 'private', 'pending')
             AND (
                 post_content LIKE '%<!-- wp:presto-player/%'
                 OR post_content LIKE '%[presto_player %'
                 OR post_content LIKE '%[presto_player]%'
                 OR post_content LIKE '%[presto_timestamp %'
                 OR post_content LIKE '%[pptime %'
                 OR post_content LIKE '%[presto_playlist%'
                 OR post_content LIKE '%[presto_popup %'
             )
             ORDER BY ID DESC
             LIMIT 1000"
        );

        $items = [];
        foreach ($posts as $post) {
            $items[] = [
                'id'        => (int) $post->ID,
                'title'     => Sanitizer::sanitizeTextField($post->post_title ?: __('(Untitled)', 'fluent-player')),
                'post_type' => $post->post_type,
                'status'    => $post->post_status,
            ];
        }

        return $items;
    }

    /**
     * Recursively find the first Presto Player media block in parsed blocks
     */
    private static function findMediaBlockRecursive($blocks)
    {
        foreach ($blocks as $block) {
            $blockName = Arr::get($block, 'blockName', '');

            if (in_array($blockName, self::$mediaBlocks, true)) {
                return [
                    'blockName' => $blockName,
                    'attrs'     => Arr::get($block, 'attrs', []),
                ];
            }

            if (!empty($block['innerBlocks'])) {
                $found = self::findMediaBlockRecursive($block['innerBlocks']);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    public static function isProtectedMedia($attrs)
    {
        return Arr::get($attrs, 'visibility') === 'private';
    }

}
