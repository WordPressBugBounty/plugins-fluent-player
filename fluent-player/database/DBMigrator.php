<?php

namespace FluentPlayer\Database;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\PresetService;
use FluentPlayer\Database\Migrations\DurationBackfillMigrator;
use FluentPlayer\Database\Migrations\EmailCollectionsMigrator;

class DBMigrator
{
    public static function run($networkWide = false)
    {
        if ($networkWide && is_multisite()) {
            $sites = get_sites();
            foreach ($sites as $site) {
                switch_to_blog($site->blog_id);
                self::migrate();
                restore_current_blog();
            }
        } else {
            self::migrate();
        }
    }

    private static function migrate()
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        self::maybeRenameTables();
        self::migratePresetsToOptions();
        EmailCollectionsMigrator::migrate();
        DurationBackfillMigrator::migrate();
    }

    private static function maybeRenameTables()
    {
        global $wpdb;

        $renames = [
            'fluent_player_presets'           => 'flp_presets',
            'fluent_player_email_collections' => 'flp_email_collections',
            'fluent_player_visits'            => 'flp_visits',
        ];

        foreach ($renames as $oldSuffix => $newSuffix) {
            $oldTable = $wpdb->prefix . $oldSuffix;
            $newTable = $wpdb->prefix . $newSuffix;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time migration check
            $oldExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $oldTable)) === $oldTable;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time migration check
            $newExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $newTable)) === $newTable;

            if ($oldExists && !$newExists) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from hardcoded strings
                $wpdb->query("RENAME TABLE `{$oldTable}` TO `{$newTable}`");
            }
        }

        foreach (['fluent_player_play_resumes', 'flp_play_resumes'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from hardcoded string
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    private static function migratePresetsToOptions()
    {
        PresetService::maybeMigrateFromTable();
        PresetService::maybeCreateDefaults();
        PresetService::syncBuiltInControls();
    }
}
