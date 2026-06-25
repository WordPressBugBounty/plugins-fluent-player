<?php

namespace FluentPlayer\Database\Migrations;

if (!defined('ABSPATH')) exit;

class EmailCollectionsMigrator
{
    public static function migrate()
    {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'flp_email_collections';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) {
            $sql = "CREATE TABLE $table (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `email` varchar(255) NOT NULL,
                `media_id` bigint(20) unsigned NULL,
                `preset_slug` varchar(255) NULL,
                `layer_id` bigint(20) unsigned NULL,
                `user_id` bigint(20) unsigned NULL,
                `video_time` float DEFAULT 0 NULL,
                `ip_address` varchar(100) NULL,
                `device` varchar(100) NULL,
                `browser` varchar(100) NULL,
                `meta` text NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) $charsetCollate;";
            dbDelta($sql);
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration code: $table is the plugin's own table name; one-shot column probe + ALTER TABLE on activation; caching not appropriate.

        // Rename provider_log → meta for existing installations
        $col = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'provider_log'
        ));
        if (!empty($col)) {
            $wpdb->query("ALTER TABLE `{$table}` CHANGE `provider_log` `meta` text NULL");
        }

        // Rename preset_id → preset_slug for existing installations
        $presetCol = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'preset_id'
        ));
        if (!empty($presetCol)) {
            $wpdb->query("ALTER TABLE `{$table}` CHANGE `preset_id` `preset_slug` varchar(255) NULL");
        }

        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        self::ensureIndexes($table);
    }

    /**
     * Add indexes used in WHERE/ORDER BY/DELETE to improve query performance.
     *
     * @param string $table Full table name (with prefix).
     */
    private static function ensureIndexes($table)
    {
        global $wpdb;
        $indexes = [
            'flp_email_collections_email_idx'          => [
                'columns' => [
                    ['name' => 'email', 'length' => 191],
                ],
            ],
            'flp_email_collections_media_id_idx'       => [
                'columns' => [
                    ['name' => 'media_id', 'length' => null],
                ],
            ],
            'flp_email_collections_preset_slug_idx'    => [
                'columns' => [
                    ['name' => 'preset_slug', 'length' => 191],
                ],
            ],
            'flp_email_collections_created_at_idx'     => [
                'columns' => [
                    ['name' => 'created_at', 'length' => null],
                ],
            ],
            'flp_email_collections_media_user_idx'     => [
                'columns' => [
                    ['name' => 'media_id', 'length' => null],
                    ['name' => 'user_id', 'length' => null],
                ],
            ],
            'flp_email_collections_media_email_idx'    => [
                'columns' => [
                    ['name' => 'media_id', 'length' => null],
                    ['name' => 'email', 'length' => 191],
                ],
            ],
        ];

        foreach ($indexes as $key => $indexDef) {
            $columnsSql = [];
            foreach ($indexDef['columns'] as $columnDef) {
                $columnName = $columnDef['name'];
                $columnsSql[] = $columnDef['length'] ? "`{$columnName}`({$columnDef['length']})" : "`{$columnName}`";
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s LIMIT 1",
                $table,
                $key
            ));
            if (!$exists) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$key}` (" . implode(', ', $columnsSql) . ")");
            }
        }
    }
}
