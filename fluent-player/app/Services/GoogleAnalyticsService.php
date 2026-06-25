<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Support\Arr;

class GoogleAnalyticsService
{
    /**
     * Enqueue Google Analytics script if enabled
     */
    public function enqueueScript()
    {
        // Google Analytics is a Pro feature: the admin UI, the event-config
        // localize, and the runtime listeners are all Pro-gated. Gate the gtag
        // enqueue too so a stale or migrated google_analytics.enabled flag does
        // not load an orphaned, event-less gtag script on a free install.
        if (!Helper::hasPro()) {
            return;
        }

        $settings = Helper::getSettings();
        $googleAnalyticsSettings = Arr::get($settings, 'google_analytics', []);

        if (!Arr::get($googleAnalyticsSettings, 'enabled', false)) {
            return;
        }

        if (Arr::get($googleAnalyticsSettings, 'use_existing_tag', false)) {
            return;
        }

        $measurementId = Arr::get($googleAnalyticsSettings, 'measurement_id');
        if (!$measurementId) {
            return;
        }

        // Load Google Analytics script with measurement ID
        wp_enqueue_script(
            'fluent_player-google-analytics',
            'https://www.googletagmanager.com/gtag/js?id=' . esc_attr($measurementId),
            [],
            FLUENT_PLAYER_VERSION,
            false
        );

        // Add inline script
        $inlineScript = "
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            gtag('js', new Date());
            gtag('config', '" . esc_js($measurementId) . "');
        ";

        wp_add_inline_script('fluent_player-google-analytics', $inlineScript);
    }
}
