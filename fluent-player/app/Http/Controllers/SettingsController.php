<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\SettingsService;
use FluentPlayer\Framework\Http\Request\Request;

class SettingsController extends Controller
{
    /**
     * Get all settings
     * @return \WP_REST_Response
     */
    public function get()
    {
        try {
            $settings = SettingsService::getSettings();
            return $this->sendSuccess([
                'settings' => $settings
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Failed to load settings. Please try again.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Update settings
     *
     * @param Request $request
     *
     * @return \WP_REST_Response
     */
    public function update(Request $request)
    {
        try {
            $settings = $request->get('settings', []);

            // Save settings using the static service
            $updatedSettings = SettingsService::saveSettings($settings);

            return $this->sendSuccess([
                'message'  => __('Settings updated successfully', 'fluent-player'),
                'settings' => $updatedSettings
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Failed to update settings. Please try again.', 'fluent-player')
            ], 400);
        }
    }

    /**
     * Reset settings to defaults
     * @return \WP_REST_Response
     */
    public function reset()
    {
        try {
            $defaultSettings = SettingsService::resetSettings();

            return $this->sendSuccess([
                'message'  => __('Settings have been reset to defaults', 'fluent-player'),
                'settings' => $defaultSettings
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Failed to reset settings. Please try again.', 'fluent-player')
            ], 400);
        }
    }
}
