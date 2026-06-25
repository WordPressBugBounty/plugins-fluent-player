<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

class ScheduledCleanupHandler
{
    const CRON_HOOK = 'fluent_player/daily_cleanup';

    /**
     * Schedule the daily cleanup cron job
     */
    public static function schedule()
    {
        // Unschedule old cron hook renamed in a previous version
        $oldTimestamp = wp_next_scheduled('fluent_player/cleanup_auto_drafts');
        if ($oldTimestamp) {
            wp_unschedule_event($oldTimestamp, 'fluent_player/cleanup_auto_drafts');
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Unschedule the cleanup cron job
     */
    public static function unschedule()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        // Also clear legacy hook if still scheduled
        $oldTimestamp = wp_next_scheduled('fluent_player/cleanup_auto_drafts');
        if ($oldTimestamp) {
            wp_unschedule_event($oldTimestamp, 'fluent_player/cleanup_auto_drafts');
        }
    }
}
