<?php

namespace FluentPlayer\App\Integrations;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Utils\Enqueuer\Vite;

abstract class AbstractIntegration
{
    /**
     * Integration identifier (slug)
     * @var string
     */
    protected $integration;

    /**
     * Integration display name
     * @var string
     */
    protected $name;

    /**
     * Integration description
     * @var string
     */
    protected $description;

    /**
     * Integration icon (URL or SVG)
     * @var string
     */
    protected $logo;

    /**
     * Default settings
     * @var array
     */
    protected $defaultSettings = [];

    /**
     * Option key for storing integration settings
     */
    const INTEGRATIONS_SETTINGS_KEY = 'fluent_player_integrations_settings';

    /**
     * Get integration identifier
     * @return string
     */
    public function getIntegration()
    {
        return $this->integration;
    }

    /**
     * Get integration name
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get integration description
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Get integration icon
     * @return string
     */
    public function getLogo()
    {
        $logoUrl = $this->logo;
        if ($logoUrl && strpos($logoUrl, 'http') !== 0) {
            $logoUrl = Vite::getEnqueuePath('images/' . $this->logo);
        }
        return $logoUrl ?: '';
    }

    /**
     * Get default settings
     * @return array
     */
    public function getDefaultSettings()
    {
        return $this->defaultSettings;
    }

    /**
     * Get settings with defaults applied
     * @return array
     */
    public function getSettingsWithDefaults()
    {
        return wp_parse_args($this->getSettings(), $this->getDefaultSettings());
    }

    /**
     * Get current settings
     * @return array
     */
    public function getSettings()
    {
        $savedSettings = get_option(self::INTEGRATIONS_SETTINGS_KEY, '');
        if ($savedSettings) {
            $allSettings = json_decode($savedSettings, true);
            if (isset($allSettings[$this->integration])) {
                return $allSettings[$this->integration];
            }
        }
        return [];
    }

    /**
     * Save settings
     * @param array $settings
     * @return array|\WP_Error
     */
    public function saveSettings($settings)
    {
        // Get all existing settings
        $allSettings = $this->getAllSettings();

        $isEnabled = isset($settings['enabled']) && $settings['enabled'] === 'yes';

        $settings['enabled'] = $isEnabled ? 'yes' : 'no';

        if (!$isEnabled) {
            if (isset($allSettings[$this->integration])) {
                unset($allSettings[$this->integration]);
            }

            // Save all settings
            update_option(self::INTEGRATIONS_SETTINGS_KEY, json_encode($allSettings));

            return [];
        }

        // For enabled integrations, validate settings first
        $validation = $this->validateSettings($settings);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // If enabled, test connection
        $test = $this->testConnection($settings);
        if (is_wp_error($test)) {
            return $test;
        }

        // Update settings
        $allSettings[$this->integration] = $settings;

        // Save all settings
        update_option(self::INTEGRATIONS_SETTINGS_KEY, json_encode($allSettings));

        return isset($allSettings[$this->integration]) ? $allSettings[$this->integration] : [];
    }

    /**
     * Get all integration settings
     * @return array
     */
    protected function getAllSettings()
    {
        $savedSettings = get_option(self::INTEGRATIONS_SETTINGS_KEY, '');
        if ($savedSettings) {
            $allSettings = json_decode($savedSettings, true);
            if (is_array($allSettings)) {
                return $allSettings;
            }
        }
        return [];
    }

    /**
     * Check if integration is enabled
     * @return bool
     */
    public function isEnabled()
    {
        $settings = $this->getSettings();
        return isset($settings['enabled']) && $settings['enabled'] === 'yes';
    }

    /**
     * Validate settings before saving
     * @param array $settings
     * @return bool|\WP_Error
     */
    abstract public function validateSettings($settings);

    /**
     * Get integration settings fields configuration
     * @return array
     */
    abstract public function getSettingsFields();

    /**
     * Test connection/configuration
     * @param array $settings Optional settings to test (uses current settings if not provided)
     * @return bool|\WP_Error
     */
    abstract public function testConnection($settings = null);

    /**
     * Handle integration-specific actions
     * @param string $action
     * @param array $data
     * @return mixed
     */
    public function handleAction($action, $data = [])
    {
        return new \WP_Error('not_implemented', __('This action is not implemented', 'fluent-player'));
    }
}