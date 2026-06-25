<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\IntegrationService;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Validator\Validator;

class IntegrationController extends Controller
{
    /**
     * Get all integration settings at once
     *
     * @return \WP_REST_Response
     */
    public function getIntegrations()
    {
        try {
            $integrations = IntegrationService::getIntegrations();
            $allSettings = [];

            foreach ($integrations as $key => $integrationClass) {
                $integration = IntegrationService::getIntegration($key);
                if ($integration) {
                    $allSettings[$key] = $integration->getSettingsWithDefaults();
                }
            }

            return $this->sendSuccess($allSettings);
        } catch (\Exception $e) {
            return $this->sendError(['message' => __('Failed to load integrations', 'fluent-player')], 400);
        }
    }

    /**
     * Save settings for any integration
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function saveIntegrationSettings(Request $request, IntegrationService $integrationService, $integration)
    {
        $integrationKey = sanitize_text_field($integration);

        // Validate request data
        $validation = Validator::make($request->all(), [
            'settings' => 'present|array'
        ]);

        if ($validation->fails()) {
            return $this->sendError([
                'message' => __('Validation failed', 'fluent-player'),
                'errors'  => $validation->errors()
            ], 422);
        }

        if (empty($integrationKey)) {
            return $this->sendError(['message' => __('Integration key is required', 'fluent-player')], 400);
        }

        $settings = $request->get('settings', []);

        try {
            $result = $integrationService->saveSettings($integrationKey, $settings);

            if (is_wp_error($result)) {
                return $this->sendError(['message' => $result->get_error_message()], 400);
            }

            return $this->sendSuccess([
                'message' => __('Integration settings saved successfully', 'fluent-player'),
                'settings' => $result
            ]);
        } catch (\Exception $e) {
            return $this->sendError(['message' => __('Failed to save integration settings', 'fluent-player')], 400);
        }
    }

    /**
     * Test connection for an integration
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function testConnection(Request $request, $integration)
    {
        $integrationKey = sanitize_text_field($integration);

        $validation = Validator::make($request->all(), [
            'settings' => 'present|array'
        ]);

        if ($validation->fails()) {
            return $this->sendError([
                'message' => __('Validation failed', 'fluent-player'),
                'errors'  => $validation->errors()
            ], 422);
        }

        if (empty($integrationKey)) {
            return $this->sendError(['message' => __('Integration key is required', 'fluent-player')], 400);
        }

        $settings = $request->get('settings', []);

        try {
            $result = IntegrationService::testConnection($integrationKey, $settings);

            if (is_wp_error($result)) {
                return $this->sendError(['message' => $result->get_error_message()], 400);
            }

            return $this->sendSuccess(['message' => __('Connection test successful', 'fluent-player')]);
        } catch (\Exception $e) {
            return $this->sendError(['message' => __('Failed to test integration connection', 'fluent-player')], 400);
        }
    }

    /**
     * Get integration fields configuration for dynamic form rendering
     *
     * @return \WP_REST_Response
     */
    public function getIntegrationFields()
    {
        try {
            $allFields = IntegrationService::getAllSettingsFields();
            return $this->sendSuccess($allFields);
        } catch (\Exception $e) {
            return $this->sendError(['message' => __('Failed to load integration fields', 'fluent-player')], 400);
        }
    }
}
