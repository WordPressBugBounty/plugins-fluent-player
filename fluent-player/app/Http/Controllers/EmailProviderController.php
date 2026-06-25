<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\EmailProviderService;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;
use FluentPlayer\Framework\Validator\Validator;

class EmailProviderController extends Controller
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // Ensure providers are initialized
        parent::__construct();
        EmailProviderService::init();
    }

    /**
     * Get all available email providers
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getProvidersSettings(Request $request)
    {
        $providers = EmailProviderService::getProvidersSettings();
        $providersMetaData = EmailProviderService::getProvidersMetaData();

        $defaultAllowedTypes = [
            'application/pdf',
            'image',
            'video',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];

        $allowedAttachmentTypes = apply_filters(
            'fluent_player/email_attachment_allowed_types',
            $defaultAllowedTypes
        );

        return $this->sendSuccess([
            'providers' => $providers,
            'providers_meta' => $providersMetaData,
            'allowed_attachment_types' => $allowedAttachmentTypes
        ]);
    }

    /**
     * Save provider settings
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function saveProviderSettings(Request $request)
    {
        $data = $request->all();
        // Validate sanitized data
        $validation = Validator::make($data, [
            'provider' => 'required|string',
            'settings' => 'required|array'
        ]);

        if ($validation->fails()) {
            return $this->sendError([
                'message' => __('Validation failed', 'fluent-player'),
                'errors'  => $validation->errors()
            ], 422);
        }

        $provider = $data['provider'];
        $settings = $data['settings'];

        $providerInstance = EmailProviderService::getProvider($provider);
        if (!$providerInstance) {
            return $this->sendError([
                'message' => __('Invalid provider', 'fluent-player')
            ], 422);
        }

        $result = EmailProviderService::saveProviderSettings($provider, $settings);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ], 400);
        }

        return $this->sendSuccess([
            'message'  => __('Settings saved successfully', 'fluent-player'),
            'settings' => $result
        ]);
    }

    /**
     * Export emails from the database
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function exportEmails(Request $request)
    {
        try {
            // Validate request data
            $validation = Validator::make($request->all(), [
                'format' => 'nullable|string|in:csv,json,ods'
            ]);

            if ($validation->fails()) {
                return $this->sendError([
                    'message' => __('Validation failed', 'fluent-player'),
                    'errors'  => $validation->errors()
                ], 422);
            }

            // Get format from request, default to CSV
            $format = $request->get('format', 'csv');

            // Sanitize format
            $format = sanitize_text_field($format);

            $shouldDownload = filter_var($request->get('download', false), FILTER_VALIDATE_BOOLEAN);
            $result = EmailProviderService::prepareEmailExport($format);

            if (is_wp_error($result)) {
                return $this->sendError([
                    'message' => $result->get_error_message()
                ], 400);
            }

            if (!$shouldDownload) {
                return $this->sendSuccess([
                    'filename' => $result['filename'],
                    'count'    => $result['count'],
                    'format'   => $result['format']
                ]);
            }

            $downloadFile = null;
            if ($result['format'] === 'ods') {
                $downloadFile = EmailProviderService::createOdsExportFile($result);

                if (is_wp_error($downloadFile)) {
                    return $this->sendError([
                        'message' => $downloadFile->get_error_message()
                    ], 400);
                }
            }

            while (ob_get_level()) {
                ob_end_clean();
            }

            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            ignore_user_abort(true);

            header('Content-Type: ' . ($downloadFile['mime_type'] ?? $result['mime_type']));
            header('Content-Disposition: attachment; filename="' . ($downloadFile['filename'] ?? $result['filename']) . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            if ($downloadFile) {
                $tmpFile = $downloadFile['file_path'];
                $fileSize = @filesize($tmpFile);
                if ($fileSize !== false) {
                    header('Content-Length: ' . $fileSize);
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- local temp file
                readfile($tmpFile);
                wp_delete_file($tmpFile);
                exit;
            }

            if ($result['format'] === 'json') {
                EmailProviderService::streamJsonExport($result);
                exit;
            }

            EmailProviderService::streamCsvExport($result);
            exit;
        } catch (\Exception $e) {
            return $this->sendError(['message' => __('Failed to export emails', 'fluent-player')], 500);
        }
    }


    /**
     * Get provider-specific resources (lists, tags, etc.)
     *
     * @param string $provider Provider type
     * @param string $resource Resource type (lists, tags, etc.)
     * @return \WP_REST_Response
     */
    public function getProviderResource(Request $request, $provider, $resource)
    {
        $params = $request->get();
        array_walk_recursive($params, function (&$value) {
            if (is_string($value)) {
                $value = sanitize_text_field($value);
            }
        });

        $result = EmailProviderService::handleProviderAction($provider, $resource, $params);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ], 422);
        }

        return $this->sendSuccess($result);
    }

    /**
     * Validate a provider field
     *
     * @param Request $request
     * @param string $provider
     * @param string $field
     * @return \WP_REST_Response
     */
    public function validateProviderField(Request $request, $provider, $field)
    {
        // Sanitize provider and field
        $provider = Sanitizer::sanitizeTextField($provider);
        $field = Sanitizer::sanitizeTextField($field);

        // Get field value from request
        $value = $request->get($field);
        if ($value === null) {
            return $this->sendError([
                'message' => __('Field value is required', 'fluent-player')
            ], 422);
        }

        $providerInstance = EmailProviderService::getProvider($provider);
        if (!$providerInstance) {
            return $this->sendError([
                'message' => __('Invalid provider', 'fluent-player')
            ], 422);
        }

        $result = $providerInstance->validateField($field, $value);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ], 400);
        }

        return $this->sendSuccess($result);
    }
}
