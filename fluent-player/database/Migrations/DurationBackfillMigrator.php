<?php

namespace FluentPlayer\Database\Migrations;

if (!defined('ABSPATH')) exit;

class DurationBackfillMigrator
{
    /**
     * Backfill duration for media items that have a WordPress attachment_id
     * but no duration set (shows as 0 sec in admin).
     *
     * Reads duration from WP attachment metadata (length_formatted) and
     * converts the formatted string (e.g. "3:45") into seconds.
     */
    public static function migrate()
    {
        if (get_option('fluent_player_duration_backfilled')) {
            return;
        }

        global $wpdb;

        // Get all fluent_player_media posts
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time migration
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = %s AND pm.meta_key = 'settings'",
                'fluent_player_media'
            )
        );

        if (empty($rows)) {
            update_option('fluent_player_duration_backfilled', 1, 'no');
            return;
        }

        foreach ($rows as $row) {
            $settings = maybe_unserialize($row->meta_value);

            if (!is_array($settings)) {
                continue;
            }

            // Skip if duration is already set
            if (!empty($settings['duration'])) {
                continue;
            }

            $attachmentId = isset($settings['attachment_id']) ? intval($settings['attachment_id']) : 0;
            if (!$attachmentId) {
                continue;
            }

            $meta = wp_get_attachment_metadata($attachmentId);
            if (empty($meta) || !is_array($meta)) {
                continue;
            }

            $duration = 0;

            // Try raw 'length' field first (seconds as float)
            if (!empty($meta['length'])) {
                $duration = floatval($meta['length']);
            }

            // Fallback: parse 'length_formatted' string (e.g. "3:45", "1:02:30")
            if (!$duration && !empty($meta['length_formatted'])) {
                $duration = self::parseDurationString($meta['length_formatted']);
            }

            if ($duration > 0) {
                $settings['duration'] = $duration;
                update_post_meta($row->post_id, 'settings', $settings);
            }
        }

        update_option('fluent_player_duration_backfilled', 1, 'no');
    }

    /**
     * Parse a duration string like "3:45" or "1:02:30" into seconds.
     *
     * @param string $str
     * @return float
     */
    private static function parseDurationString($str)
    {
        if (empty($str)) {
            return 0;
        }

        $parts = array_map('intval', explode(':', $str));

        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        return floatval($parts[0]);
    }
}
