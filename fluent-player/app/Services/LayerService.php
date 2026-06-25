<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

class LayerService
{

    /**
     * Get all forms by type
     *
     * @param string $formType
     * @return array
     * @throws \Exception
     */
    public static function getFormsByType($formType)
    {
        $formType = sanitize_text_field($formType);
        if (!$formType) {
            throw new \Exception('Form type is required');
        }
        if (!self::isFormPluginActive($formType)) {
            throw new \Exception('Form plugin is not active');
        }

        switch ($formType) {
            case 'fluentforms':
                return self::getFluentForms();
            default:
                return [];
        }
    }

    /**
     * Get form preview HTML
     *
     * @param string $formType
     * @param string $formId
     * @return string
     */
    public static function getFormsPreview($formType, $formId)
    {
        $formId = intval($formId);
        $formType = sanitize_text_field($formType);
        if (!$formType || !$formId) {
            throw new \Exception('Form type and form ID are required');
        }
        if (!self::isFormPluginActive($formType)) {
            throw new \Exception('Form plugin is not active');
        }

        ob_start();
        switch ($formType) {
            case 'fluentforms':
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form content is sanitized by form plugin
                echo self::getFluentFormsPreview($formId);
                break;
            default:
                echo '';
        }
        return ob_get_clean();
    }

    /**
     * Get fluent form preview HTML
     *
     * @param int $formId
     * @return string
     */
    private static function getFluentFormsPreview($formId)
    {
        $content = '';
        if (\FluentForm\App\Helpers\Helper::isConversionForm($formId)) {
            $content = '<div class="form-preview-info"><p>' . __('This is a conversational form. It will show in conversational mode on player.', 'fluent-player') . '</p></div>';
        }
        return $content . do_shortcode("[fluentform id=\"{$formId}\"]");
    }

    /**
     * Get all fluent forms
     *
     * @return array
     */
    private static function getFluentForms()
    {
        $forms = \FluentForm\App\Helpers\Helper::getForms();
        $formatedForms = [];
        foreach ($forms as $formId => $title) {
            if ($formId) {
                $formatedForms[] = [
                    'id'    => $formId,
                    'title' => $title,
                ];
            }
        }
        return $formatedForms;
    }


    /**
     * Check if form plugin is active
     *
     * @param string $formType
     * @return bool
     */
    public static function isFormPluginActive($formType)
    {
        switch ($formType) {
            case 'fluentforms':
                return defined('FLUENTFORM');
            default:
                return false;
        }
    }

    /**
     * Get all supported form types with their status
     *
     * @return array
     */
    public static function getFormTypesStatus()
    {
        $formTypes = [
            'fluentforms' => 'Fluent Forms',
        ];

        $status = [];
        foreach ($formTypes as $type => $name) {
            $status[$type] = [
                'name' => $name,
                'enable' => self::isFormPluginActive($type),
                'forms' => []
            ];
        }

        return $status;
    }

    /**
     * Get shortcode preview HTML
     *
     * @param string $shortcode
     * @return string
     * @throws \Exception
     */
    public static function getShortcodePreview($shortcode)
    {
        $shortcode = sanitize_text_field($shortcode);
        if (!$shortcode) {
            throw new \Exception('Shortcode is required');
        }

        ob_start();
        echo do_shortcode($shortcode);
        return ob_get_clean();
    }
}
