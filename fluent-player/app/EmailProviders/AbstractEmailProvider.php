<?php

namespace FluentPlayer\App\EmailProviders;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Utils\Enqueuer\Vite;

abstract class AbstractEmailProvider
{
    /**
     * Provider identifier (slug)
     * @var string
     */
    protected $provider;

    /**
     * Provider display name
     * @var string
     */
    protected $name;

    /**
     * Provider description
     * @var string
     */
    protected $description;

    /**
     * Provider icon (URL or SVG)
     * @var string
     */
    protected $logo;

    /**
     * Default settings
     * @var array
     */
    protected $defaultSettings = [];

    /**
     * Get provider identifier
     * @return string
     */
    public function getProvider()
    {
        return $this->provider;
    }

    /**
     * Get provider name
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get provider description
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Get provider icon
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
     * Validate settings before saving
     * @param array $settings
     * @return array|\WP_Error
     */
    abstract public function validateSettings($settings);

    /**
     * Sanitize settings before saving
     * @param array $settings
     * @return array
     */
    abstract public function sanitizeSettings($settings);

    /**
     * Get provider settings fields configuration for frontend
     * @return array
     */
    abstract public function getSettingsFields();

    /**
     * Subscribe an email address to the provider
     * @param string $email
     * @param array $data Additional data
     * @param array $settings Provider settings
     * @return array|\WP_Error
     */
    abstract public function subscribe($email, $data, $settings);

    /**
     * Check if provider is properly configured
     * @param array $settings
     * @return bool
     */
    abstract public function isConfigured($settings);

    /**
     * Handle provider-specific actions
     * @param string $action
     * @param array $settings
     * @param array $data
     * @return array|\WP_Error
     */
    public function handleAction($action, $settings, $data = [])
    {
        return new \WP_Error('not_implemented', __('This action is not implemented', 'fluent-player'));
    }

    /**
     * Validate a specific field value
     * @param string $field Field key
     * @param mixed $value Field value
     * @return array|\WP_Error
     */
    public function validateField($field, $value)
    {
        return new \WP_Error('not_implemented', __('Field validation not implemented for this provider', 'fluent-player'));
    }

    /**
     * Verify the connection status of the provider
     * This can be overridden by child classes
     *
     * @param array $settings
     * @return array Updated settings
     */
    public function verifyConnectionStatus($settings)
    {
        return $settings;
    }
}