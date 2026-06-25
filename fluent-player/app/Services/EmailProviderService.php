<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\EmailCollection;
use FluentPlayer\App\EmailProviders\AbstractEmailProvider;
use FluentPlayer\Framework\Support\Arr;

class EmailProviderService
{
    const EXPORT_CHUNK_SIZE = 500;

    /**
     * Option key for storing email provider settings
     */
    const EMAIL_PROVIDERS_SETTINGS_KEY = 'fluent_player_email_providers';

    /**
     * Provider instances
     * @var array
     */
    protected static $providers = [];
    
    protected static $settingsCache = null;

    /**
     * Initialize the service and register default providers
     */
    public static function init()
    {
        // Allow providers to be registered
        do_action('fluent_player/register_email_providers');
    }

    /**
     * Register a provider
     * @param AbstractEmailProvider $provider
     */
    public static function registerProvider(AbstractEmailProvider $provider)
    {
        $providerKey = $provider->getProvider();
        self::$providers[$providerKey] = $provider;
    }

    /**
     * Get all registered providers
     * @return array
     */
    public static function getRegisteredProviders()
    {
        return self::$providers;
    }

    /**
     * Get a specific provider
     * @param string $provider
     * @return AbstractEmailProvider|null
     */
    public static function getProvider($provider)
    {
        return self::$providers[$provider] ?? null;
    }

    /**
     * Get all provider settings
     * @return array
     */
    public static function getProvidersSettings()
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }
        
        $savedSettings = get_option(self::EMAIL_PROVIDERS_SETTINGS_KEY, '');
        if ($savedSettings) {
            $settings = json_decode($savedSettings, true);
        } else {
            $settings = [];
        }

        // Initialize default settings for registered providers
        foreach (self::$providers as $providerKey => $provider) {
            if (!isset($settings[$providerKey])) {
                $settings[$providerKey] = $provider->getDefaultSettings();
            }

            if (method_exists($provider, 'verifyConnectionStatus')) {
                $settings[$providerKey] = $provider->verifyConnectionStatus($settings[$providerKey]);
            }
        }

        if (!Helper::hasPro()) {
            foreach (array_keys($settings) as $providerKey) {
                if (!isset(self::$providers[$providerKey])) {
                    unset($settings[$providerKey]);
                }
            }
        }

        self::$settingsCache = $settings;

        return $settings;
    }
    
    /**
     * Clear email provider settings cache (call after updates)
     */
    public static function clearCache()
    {
        self::$settingsCache = null;
    }

    /**
     * Save provider settings
     * @param string $provider
     * @param array $settings
     * @return array
     */
    public static function saveProviderSettings($provider, $settings)
    {
        $providerInstance = self::getProvider($provider);

        if (!$providerInstance) {
            return new \WP_Error('invalid_provider', __('Invalid provider', 'fluent-player'));
        }

        // Sanitize settings
        $settings = $providerInstance->sanitizeSettings($settings);

        // Validate settings
        $validationResult = $providerInstance->validateSettings($settings);
        if (is_wp_error($validationResult)) {
            return $validationResult;
        }

        // Get all settings and update with new data
        $allSettings = self::getProvidersSettings();

        if (!isset($allSettings[$provider])) {
            $allSettings[$provider] = $providerInstance->getDefaultSettings();
        }

        // Update settings with new data
        foreach ($settings as $key => $value) {
            $allSettings[$provider][$key] = $value;
        }

        // Save all settings
        update_option(self::EMAIL_PROVIDERS_SETTINGS_KEY, json_encode($allSettings));
        
        self::clearCache();
        
        if (class_exists('\FluentPlayerPro\App\EmailProviders\WebhookProvider')) {
            \FluentPlayerPro\App\EmailProviders\WebhookProvider::clearCache();
        }

        return $allSettings[$provider];
    }

    /**
     * Get configured providers
     * @return array
     */
    public static function getConfiguredProviders()
    {
        $allSettings = self::getProvidersSettings();
        $configuredProviders = [];

        foreach (self::$providers as $providerKey => $providerInstance) {
            $providerSettings = $allSettings[$providerKey] ?? $providerInstance->getDefaultSettings();

            // Only include if the provider is properly configured
            if ($providerInstance->isConfigured($providerSettings)) {
                $configuredProviders[$providerKey] = $providerSettings;
            }
        }

        return $configuredProviders;
    }

    /**
     * Get provider meta data for frontend
     * @return array
     */
    public static function getProvidersMetaData()
    {
        $providersMeta = [];

        foreach (self::$providers as $providerKey => $providerInstance) {
            $providersMeta[$providerKey] = [
                'name' => $providerInstance->getName(),
                'description' => $providerInstance->getDescription(),
                'logo' => $providerInstance->getLogo(),
                'settings_fields' => $providerInstance->getSettingsFields()
            ];
        }

        if (!Helper::hasPro()) {
            $placeholders = apply_filters('fluent_player/email_provider_placeholder_meta', []);
            foreach ($placeholders as $providerKey => $meta) {
                if (!isset($providersMeta[$providerKey])) {
                    $providersMeta[$providerKey] = $meta;
                }
            }
        }

        $providersMeta = apply_filters('fluent_player/email_provider_meta', $providersMeta);

        return $providersMeta;
    }

    /**
     * Subscribe email to all configured providers
     * @param string $email
     * @param array $data Additional data
     * @return array Results for each provider
     */
    public static function subscribeToProviders($email, $data = [])
    {
        $results = [];
        $configuredProviders = self::getConfiguredProviders();

        foreach ($configuredProviders as $providerKey => $settings) {
            $providerInstance = self::getProvider($providerKey);

            if ($providerInstance) {
                $result = $providerInstance->subscribe($email, $data, $settings);

                $results[$providerKey] = [
                    'success' => !is_wp_error($result),
                    'message' => is_wp_error($result) ? $result->get_error_message() : __('Subscription successful', 'fluent-player'),
                    'data' => !is_wp_error($result) ? $result : null
                ];
            }
        }

        return $results;
    }

    /**
     * Handle provider-specific action
     * @param string $provider
     * @param string $action
     * @param array $data
     * @return array|\WP_Error
     */
    public static function handleProviderAction($provider, $action, $data = [])
    {
        $providerInstance = self::getProvider($provider);

        if (!$providerInstance) {
            return new \WP_Error('invalid_provider', __('Invalid provider', 'fluent-player'));
        }

        $settings = self::getProvidersSettings();
        $providerSettings = $settings[$provider] ?? [];

        return $providerInstance->handleAction($action, $providerSettings, $data);
    }

    /**
     * All columns in the email collections table.
     * Used to validate filter output and prevent SQL injection.
     */
    const ALLOWED_EXPORT_COLUMNS = [
        'id', 'email', 'media_id', 'preset_slug', 'layer_id',
        'video_time', 'ip_address', 'browser', 'device',
        'user_id', 'meta', 'created_at', 'updated_at'
    ];

    /**
     * Human-readable header labels for export columns.
     */
    const EXPORT_COLUMN_HEADERS = [
        'id'          => 'ID',
        'email'       => 'Email Address',
        'media_id'    => 'Media ID',
        'preset_slug' => 'Preset',
        'layer_id'    => 'Layer ID',
        'video_time'  => 'Video Time',
        'ip_address'  => 'IP Address',
        'browser'     => 'Browser',
        'device'      => 'Device',
        'user_id'     => 'User ID',
        'meta'        => 'Provider Log',
        'created_at'  => 'Created At',
        'updated_at'  => 'Updated At',
    ];

    /**
     * Prepare the shared export definition for email submissions.
     *
     * @param string $format Export format (csv, json, ods)
     * @return array|\WP_Error
     */
    public static function prepareEmailExport($format = 'csv')
    {
        try {
            $format = in_array($format, ['csv', 'json', 'ods'], true) ? $format : 'csv';

            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed
            $columns = apply_filters('fluent_player/email_export_columns', [
                'email', 'media_id', 'preset_slug', 'created_at'
            ]);

            $columns = array_values(array_intersect((array) $columns, self::ALLOWED_EXPORT_COLUMNS));

            if (empty($columns)) {
                $columns = ['email', 'created_at'];
            }

            $selectColumns = $columns;
            if (!in_array('id', $selectColumns, true)) {
                $selectColumns = array_merge(['id'], $selectColumns);
            }

            $query = EmailCollection::select($selectColumns);
            $count = (clone $query)->count();

            if ($count === 0) {
                return new \WP_Error('no_data', __('No email submissions found to export', 'fluent-player'));
            }

            $translatedHeaders = [
                'id'          => __('ID', 'fluent-player'),
                'email'       => __('Email Address', 'fluent-player'),
                'media_id'    => __('Media ID', 'fluent-player'),
                'preset_slug' => __('Preset', 'fluent-player'),
                'layer_id'    => __('Layer ID', 'fluent-player'),
                'video_time'  => __('Video Time', 'fluent-player'),
                'ip_address'  => __('IP Address', 'fluent-player'),
                'browser'     => __('Browser', 'fluent-player'),
                'device'      => __('Device', 'fluent-player'),
                'user_id'     => __('User ID', 'fluent-player'),
                'meta'        => __('Provider Log', 'fluent-player'),
                'created_at'  => __('Created At', 'fluent-player'),
                'updated_at'  => __('Updated At', 'fluent-player'),
            ];
            $headers = [];
            foreach ($columns as $col) {
                $headers[$col] = Arr::get($translatedHeaders, $col, Arr::get(self::EXPORT_COLUMN_HEADERS, $col, $col));
            }

            $mimeTypes = [
                'csv'  => 'text/csv',
                'json' => 'application/json',
                'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
            ];

            return [
                'format'    => $format,
                'filename'  => 'fluent-player-email-export-' . gmdate('Y-m-d') . '.' . $format,
                'mime_type' => $mimeTypes[$format],
                'headers'   => $headers,
                'columns'   => $columns,
                'query'     => $query,
                'count'     => $count,
            ];
        } catch (\Exception $e) {
            return new \WP_Error('export_error', $e->getMessage());
        }
    }

    /**
     * Format a single database row for export.
     *
     * @param object $item  Database row
     * @param array  $columns Columns to include
     * @return array
     */
    private static function formatExportRow($item, $columns)
    {
        $row = [];
        foreach ($columns as $field) {
            $row[$field] = $item->{$field};
        }

        if (Arr::get($row, 'created_at')) {
            $row['created_at'] = gmdate('Y-m-d H:i:s', strtotime($row['created_at']));
        }

        if (Arr::get($row, 'updated_at')) {
            $row['updated_at'] = gmdate('Y-m-d H:i:s', strtotime($row['updated_at']));
        }

        $meta = Arr::get($row, 'meta');
        if ($meta) {
            if (is_string($meta)) {
                $meta = json_decode($meta, true) ?: [];
            }
            $providerLog = Arr::get($meta, 'provider_log', $meta);
            $row['meta'] = is_array($providerLog) ? json_encode($providerLog, JSON_PRETTY_PRINT) : $providerLog;
        }

        return $row;
    }

    /**
     * Neutralize spreadsheet formula injection in a single export cell.
     *
     * Cells beginning with = + - @ \t \r execute as formulas when the
     * export is opened in Excel, LibreOffice, or Google Sheets. Prefixing
     * a single quote makes spreadsheet apps treat the cell as text.
     * Applied to CSV and ODS output only — JSON keeps raw values.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function sanitizeSpreadsheetCell($value)
    {
        if (!is_string($value) || '' === $value) {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Format a row for spreadsheet output with formula defanging.
     *
     * @param object $item    Database row
     * @param array  $columns Columns to include
     * @return array
     */
    private static function formatSpreadsheetRow($item, $columns)
    {
        return array_map([self::class, 'sanitizeSpreadsheetCell'], self::formatExportRow($item, $columns));
    }

    /**
     * Stream CSV export directly to the HTTP response.
     *
     * @param array $export Prepared export definition
     * @return void
     */
    public static function streamCsvExport(array $export)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is a PHP stream
        $output = fopen('php://output', 'w');

        fputcsv($output, array_values($export['headers']), ',', '"', '\\');

        $export['query']->chunkByIdDesc(self::EXPORT_CHUNK_SIZE, function ($rows) use ($output, $export) {
            foreach ($rows as $item) {
                fputcsv($output, self::formatSpreadsheetRow($item, $export['columns']), ',', '"', '\\');
            }
            fflush($output);
        });
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is a PHP stream
        fclose($output);
    }

    /**
     * Stream JSON export directly to the HTTP response.
     *
     * @param array $export Prepared export definition
     * @return void
     */
    public static function streamJsonExport(array $export)
    {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming JSON export to php://output; WP_Filesystem is not suitable for HTTP response streams.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is a PHP stream
        $output = fopen('php://output', 'w');
        $isFirstRow = true;

        fwrite($output, "[\n");

        $export['query']->chunkByIdDesc(self::EXPORT_CHUNK_SIZE, function ($rows) use ($output, $export, &$isFirstRow) {
            foreach ($rows as $item) {
                if (!$isFirstRow) {
                    fwrite($output, ",\n");
                }

                fwrite(
                    $output,
                    json_encode(
                        self::formatExportRow($item, $export['columns']),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    )
                );

                $isFirstRow = false;
            }
            fflush($output);
        });

        fwrite($output, "\n]");

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is a PHP stream
        fclose($output);
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
    }

    /**
     * Generate an ODS export file without materializing the full document in memory.
     *
     * @param array $export Prepared export definition
     * @return array|\WP_Error
     */
    public static function createOdsExportFile(array $export)
    {
        if (!class_exists('ZipArchive')) {
            return new \WP_Error('missing_dependency', __('ZipArchive is required to export ODS files. Please enable the zip PHP extension.', 'fluent-player'));
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fluent-player-ods-' . uniqid('', true);
        $metaDir = $tmpDir . DIRECTORY_SEPARATOR . 'META-INF';
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fluent-player-ods-' . uniqid('', true) . '.ods';

        if (!wp_mkdir_p($metaDir)) {
            return new \WP_Error('export_error', __('Could not prepare temporary export files.', 'fluent-player'));
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- ODS export streams XML to a local temp file; WP_Filesystem does not expose stream-write semantics needed for chunked queries.

        $contentPath = $tmpDir . DIRECTORY_SEPARATOR . 'content.xml';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local temp file
        $contentHandle = fopen($contentPath, 'w');
        if (!$contentHandle) {
            self::cleanupTemporaryDirectory($tmpDir);
            return new \WP_Error('export_error', __('Could not prepare export content file.', 'fluent-player'));
        }

        fwrite(
            $contentHandle,
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-content'
            . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
            . ' office:version="1.2">'
            . '<office:body><office:spreadsheet>'
            . '<table:table table:name="Sheet1">'
        );

        fwrite($contentHandle, self::buildOdsRow(array_values($export['headers'])));

        $export['query']->chunkByIdDesc(self::EXPORT_CHUNK_SIZE, function ($rows) use ($contentHandle, $export) {
            foreach ($rows as $item) {
                fwrite(
                    $contentHandle,
                    self::buildOdsRow(array_values(self::formatSpreadsheetRow($item, $export['columns'])))
                );
            }
            fflush($contentHandle);
        });

        fwrite($contentHandle, '</table:table></office:spreadsheet></office:body></office:document-content>');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- local temp file
        fclose($contentHandle);
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

        $manifestXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"'
            . ' manifest:version="1.2">'
            . '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
            . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
            . '</manifest:manifest>';

        $mimetypePath = $tmpDir . DIRECTORY_SEPARATOR . 'mimetype';
        $manifestPath = $metaDir . DIRECTORY_SEPARATOR . 'manifest.xml';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local temp files
        file_put_contents($mimetypePath, 'application/vnd.oasis.opendocument.spreadsheet');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local temp files
        file_put_contents($manifestPath, $manifestXml);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            self::cleanupTemporaryDirectory($tmpDir);
            return new \WP_Error('zip_error', __('Could not create ODS archive.', 'fluent-player'));
        }

        // mimetype must be the first entry and stored uncompressed (ODS spec requirement)
        $zip->addFile($mimetypePath, 'mimetype');
        if (defined('ZipArchive::CM_STORE')) {
            $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        }
        $zip->addFile($manifestPath, 'META-INF/manifest.xml');
        $zip->addFile($contentPath, 'content.xml');
        $zip->close();
        self::cleanupTemporaryDirectory($tmpDir);

        return [
            'success'   => true,
            'filename'  => $export['filename'],
            'file_path' => $zipPath,
            'mime_type' => 'application/vnd.oasis.opendocument.spreadsheet',
            'count'     => $export['count']
        ];
    }

    /**
     * Build a single ODS table row XML string
     *
     * @param array $cells Cell values
     * @return string
     */
    private static function buildOdsRow(array $cells)
    {
        $cellsXml = '';
        foreach ($cells as $value) {
            $escaped = htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');
            $cellsXml .= '<table:table-cell office:value-type="string">'
                . '<text:p>' . $escaped . '</text:p>'
                . '</table:table-cell>';
        }
        return '<table:table-row>' . $cellsXml . '</table:table-row>';
    }

    /**
     * Remove a temporary export directory and its contents.
     *
     * @param string $directory
     * @return void
     */
    private static function cleanupTemporaryDirectory($directory)
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = array_diff(scandir($directory), ['.', '..']);
        foreach ($files as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                self::cleanupTemporaryDirectory($path);
                continue;
            }
            wp_delete_file($path);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleaning up our own sys_get_temp_dir() workspace.
        @rmdir($directory);
    }

    /**
     * Validate a provider field
     * @param string $provider
     * @param string $field
     * @param mixed $value
     * @return array|\WP_Error
     */
    public static function validateProviderField($provider, $field, $value)
    {
        $providerInstance = self::getProvider($provider);

        if (!$providerInstance) {
            return new \WP_Error('invalid_provider', __('Invalid provider', 'fluent-player'));
        }

        return $providerInstance->validateField($field, $value);
    }
}
