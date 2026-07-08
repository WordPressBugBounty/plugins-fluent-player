<?php

namespace FluentPlayer\Database\Migrations;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\PresetService;

class ResumePlaybackResetMigrator
{
    /**
     * One-time reset for the resume-playback cascade: strip save_play_position
     * from reserved presets and drop the stale flat copy from every media, so
     * media inherit preset/global and only a deliberate toggle overrides.
     */
    public static function migrate()
    {
        if (get_option('fluent_player_resume_reset_done')) {
            return;
        }

        self::stripReservedPresetResume();
        self::dropMediaResumeOverrides();

        update_option('fluent_player_resume_reset_done', 1, 'no');
    }

    private static function stripReservedPresetResume()
    {
        $stored = get_option(PresetService::OPTION_KEY);
        if (is_string($stored)) {
            $presets = json_decode($stored, true);
        } else {
            $presets = $stored;
        }

        if (!is_array($presets) || empty($presets)) {
            return;
        }

        $changed = false;
        foreach (PresetService::RESERVED_SLUGS as $slug) {
            if (isset($presets[$slug]['settings']['behaviors']) &&
                is_array($presets[$slug]['settings']['behaviors']) &&
                array_key_exists('save_play_position', $presets[$slug]['settings']['behaviors'])
            ) {
                unset($presets[$slug]['settings']['behaviors']['save_play_position']);
                $changed = true;
            }
        }

        if ($changed) {
            update_option(PresetService::OPTION_KEY, wp_json_encode($presets), true);
            PresetService::clearCache();
        }
    }

    private static function dropMediaResumeOverrides()
    {
        global $wpdb;

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
            return;
        }

        foreach ($rows as $row) {
            $settings = maybe_unserialize($row->meta_value);

            if (!is_array($settings) || !array_key_exists('save_play_position', $settings)) {
                continue;
            }

            unset($settings['save_play_position']);
            if (isset($settings['behaviors']) && is_array($settings['behaviors'])) {
                unset($settings['behaviors']['save_play_position']);
            }

            update_post_meta($row->post_id, 'settings', $settings);
        }
    }
}
