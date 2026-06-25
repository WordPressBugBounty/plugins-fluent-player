<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Integrations\AbstractIntegration;
use FluentPlayer\Framework\Support\Arr;

class IntegrationService
{
    /**
     * Registered integration classes
     * @var array
     */
    protected static $integrations = [];

    /**
     * Integration instances cache
     * @var array
     */
    protected static $instances = [];

    /**
     * Register an integration
     * @param string $key
     * @param string $className Full class name of integration
     */
    public static function register($key, $className)
    {
        self::$integrations[$key] = $className;
    }

    /**
     * Get all registered integrations
     * @return array
     */
    public static function getIntegrations()
    {
        // Apply filter to allow plugins to register additional integrations
        $integrations = apply_filters('fluent_player/integrations', self::$integrations);

        return $integrations;
    }

    /**
     * Get a specific integration instance
     * @param string $key
     * @return AbstractIntegration|null
     */
    public static function getIntegration($key)
    {
        // Return from cache if exists
        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        $integrations = self::getIntegrations();

        // Check if integration is registered
        if (!isset($integrations[$key])) {
            return null;
        }

        $className = $integrations[$key];

        // Check if class exists
        if (!class_exists($className)) {
            return null;
        }

        // Create instance and cache it
        $instance = new $className();

        // Store in cache
        self::$instances[$key] = $instance;

        return $instance;
    }

    /**
     * Get all integration settings fields
     * @return array
     */
    public static function getAllSettingsFields()
    {
        $allFields = [];
        $integrations = self::getIntegrations();

        foreach ($integrations as $key => $className) {
            $integration = self::getIntegration($key);
            if ($integration) {
                $allFields[$key] = [
                    'name' => $integration->getName(),
                    'description' => $integration->getDescription(),
                    'logo' => $integration->getLogo(),
                    'fields' => $integration->getSettingsFields()
                ];
            }
        }

        return $allFields;
    }

    /**
     * Save settings for a specific integration
     * @param string $key Integration key
     * @param array $settings Settings to save
     * @return array|\WP_Error
     */
    public static function saveSettings($key, $settings)
    {
        $integration = self::getIntegration($key);
        if (!$integration) {
            return new \WP_Error('integration_not_found', __('Integration not found', 'fluent-player'));
        }

        return $integration->saveSettings($settings);
    }

    /**
     * Test connection for a specific integration
     * @param string $key Integration key
     * @param array $settings Settings to test
     * @return bool|\WP_Error
     */
    public static function testConnection($key, $settings)
    {
        $integration = self::getIntegration($key);
        if (!$integration) {
            return new \WP_Error('integration_not_found', __('Integration not found', 'fluent-player'));
        }

        return $integration->testConnection($settings);
    }

    /**
     * Handle integration-specific actions
     * @param string $key Integration key
     * @param string $action Action to perform
     * @param array $data Additional data for the action
     * @return mixed|\WP_Error
     */
    public static function handleAction($key, $action, $data = [])
    {
        $integration = self::getIntegration($key);
        if (!$integration) {
            return new \WP_Error('integration_not_found', __('Integration not found', 'fluent-player'));
        }

        return $integration->handleAction($action, $data);
    }
}
