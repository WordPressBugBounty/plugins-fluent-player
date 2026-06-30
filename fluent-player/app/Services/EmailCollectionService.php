<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Support\Arr;

class EmailCollectionService
{
    /**
     * Build a nonce action bound to the specific rendered email-capture target.
     *
     * @param string $type
     * @param int $mediaId
     * @param string $presetSlug
     * @param string $layerId
     * @return string
     */
    public static function getNonceAction($type, $mediaId = 0, $presetSlug = '', $layerId = '')
    {
        $type = sanitize_text_field((string) $type);
        $mediaId = absint($mediaId);
        $presetSlug = sanitize_text_field((string) $presetSlug);
        $layerId = sanitize_text_field((string) $layerId);

        if ($type === 'layer') {
            return sprintf('fluent_player_email_submit:layer:%d:%s', $mediaId, $layerId);
        }

        return sprintf('fluent_player_email_submit:preset:%d:%s', $mediaId, $presetSlug);
    }

    /**
     * Process any provider dynamically
     *
     * @param string $providerType Provider type
     * @param string $email
     * @param array $config
     * @return array
     */
    public function processProvider($providerType, $email, $config)
    {
        try {
            $preProcessResult = apply_filters('fluent_player/pre_process_email_provider', null, $providerType, $email, $config);
            if ($preProcessResult !== null) {
                return $preProcessResult;
            }
    
            if ($providerType === 'email') {
                return $this->processEmail($email, $config);
            }
            
            if (empty(EmailProviderService::getRegisteredProviders())) {
                EmailProviderService::init();
            }

            // Get provider instance from the EmailProviderService
            $provider = EmailProviderService::getProvider($providerType);

            if (!$provider) {
                return [
                    'success' => false,
                    // translators: %s: provider type name
                    'message' => sprintf(__('Provider "%s" not found', 'fluent-player'), $providerType)
                ];
            }

            // Extract data from config for the provider
            $data = [];
            if (!empty($config['name'])) {
                $data['name'] = $config['name'];
            }

            $result = $provider->subscribe($email, $data, $config);

            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code()
                ];
            }

            $response = [
                'success' => true,
                'message' => __('Subscription successful', 'fluent-player'),
                'data'    => $result
            ];

            return apply_filters('fluent_player/post_process_email_provider', $response, $providerType, $email, $config);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => $e->getCode() ?: 500
            ];
        }
    }

    /**
     * Process email notification
     *
     * @param string $email
     * @param array $config
     * @return array
     */
    public function processEmail($email, $config)
    {
        try {
            $subject = Arr::get($config, 'email_subject', 'Thank you for subscribing');
            $body = Arr::get($config, 'email_body', 'Thank you for subscribing to our newsletter.');

            $headers = [
                'Content-Type: text/html; charset=utf-8'
            ];

            // Replace placeholders
            $placeholders = [
                '{{email}}'     => $email,
                '{{site_name}}' => get_bloginfo('name'),
                '{{site_url}}'  => get_bloginfo('url'),
                '{{date}}'      => date_i18n(get_option('date_format')),
                '{{time}}'      => date_i18n(get_option('time_format'))
            ];

            foreach ($placeholders as $placeholder => $value) {
                $body = str_replace($placeholder, $value, $body);
            }

            $formattedBody = $this->formatEmailBody($body);

            $formattedBody = apply_filters('fluent_player/email_template', $formattedBody, $email, $config);

            // Process attachments
            $attachments = [];
            if ($mediaAttachments = Arr::get($config, 'attachments')) {
                foreach ($mediaAttachments as $attachment) {
                    $attachmentId = Arr::get($attachment, 'id');
                    $attachmentUrl = Arr::get($attachment, 'url');

                    // Try to get file path from attachment ID first
                    if ($attachmentId) {
                        $filePath = get_attached_file($attachmentId);
                        if ($filePath && file_exists($filePath)) {
                            $attachments[] = $filePath;
                            continue;
                        }
                    }

                    // If ID method fails, try to get from URL
                    if ($attachmentUrl) {
                        $filePath = $this->resolveUploadAttachmentPathFromUrl($attachmentUrl);

                        if ($filePath) {
                            $attachments[] = $filePath;
                        }
                    }
                }
            }

            $emailData = apply_filters('fluent_player/email_data', [
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $formattedBody,
                'headers'     => $headers,
                'attachments' => $attachments
            ], $email, $config);

            $sent = wp_mail(
                $emailData['to'],
                $emailData['subject'],
                $emailData['body'],
                $emailData['headers'],
                $emailData['attachments']
            );

            return [
                'success' => $sent,
                'message' => $sent ? __('Email sent successfully', 'fluent-player') : __('Failed to send email', 'fluent-player'),
                'code'    => $sent ? 200 : 500
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => $e->getCode()
            ];
        }
    }

    /**
     * Resolve a local uploads attachment path from a stored uploads URL.
     *
     * The resolved file must remain under the uploads base directory.
     *
     * @param string $attachmentUrl
     * @param array|null $uploadDir
     * @return string|false
     */
    public function resolveUploadAttachmentPathFromUrl($attachmentUrl, $uploadDir = null)
    {
        if (!$attachmentUrl) {
            return false;
        }

        $uploadDir = $uploadDir ?: wp_upload_dir();
        $baseUrl = untrailingslashit((string) Arr::get($uploadDir, 'baseurl'));
        $baseDir = Arr::get($uploadDir, 'basedir');

        if (!$baseUrl || !$baseDir) {
            return false;
        }

        if (strpos($attachmentUrl, $baseUrl) !== 0) {
            return false;
        }

        $relativePath = rawurldecode(substr($attachmentUrl, strlen($baseUrl)));
        $candidatePath = wp_normalize_path($baseDir . $relativePath);
        $resolvedPath = realpath($candidatePath);
        $resolvedBaseDir = realpath($baseDir);

        if (!$resolvedPath || !$resolvedBaseDir || !is_file($resolvedPath)) {
            return false;
        }

        $resolvedPath = wp_normalize_path($resolvedPath);
        $resolvedBaseDir = trailingslashit(wp_normalize_path($resolvedBaseDir));

        if (strpos($resolvedPath, $resolvedBaseDir) !== 0) {
            return false;
        }

        return $resolvedPath;
    }

    /**
     * Format email body for HTML emails
     *
     * @param string $body
     * @return string
     */
    public function formatEmailBody($body)
    {
        $body = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');

        $body = '<p>' . str_replace("\n\n", '</p><p>', $body) . '</p>';

        $body = str_replace("\n", '<br>', $body);

        $body = str_replace('<p></p>', '', $body);

        // Default email styles
        $defaultStyles = [
            'container'     => 'max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;',
            'content_box'   => 'background-color: #ffffff; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);',
            'header'        => 'text-align: center; padding: 20px;',
            'header_border' => 'border-top: 4px solid #5c9aff; margin: 0;',
            'content'       => 'padding: 20px 30px; color: #333333; font-family: Arial, sans-serif; line-height: 1.6;',
            'paragraph'     => 'margin-bottom: 16px; font-size: 15px;',
            'heading'       => 'color: #333333; font-size: 20px; margin-top: 0; margin-bottom: 20px; text-align: center;',
            'footer'        => 'margin-top: 20px; font-size: 13px; color: #777777; text-align: center; padding: 10px;',
            'link'          => 'color: #0073aa; text-decoration: underline;'
        ];

        $styles = apply_filters('fluent_player/email_styles', $defaultStyles);

        // Apply paragraph styling to all p tags
        $body = str_replace('<p>', '<p style="' . $styles['paragraph'] . '">', $body);

        $logo = '<h2 style="margin: 0; color: #333333;">' . get_bloginfo('name') . '</h2>';

        // Get the current date and time
        $date_time = current_time('Y-m-d H:i:s');

        $showPoweredBy = !Helper::hasPro() || (bool) Arr::get(Helper::getSettings(), 'branding.show_powered_by', false);
        $poweredBy = $showPoweredBy ? '<p>Powered by FluentPlayer</p>' : '';

        // Create the email template
        return '
        <div style="' . $styles['container'] . '">
            <div style="' . $styles['content_box'] . '">
                <div style="' . $styles['header'] . '">
                    ' . $logo . '
                </div>
                <hr style="' . $styles['header_border'] . '">
                <div style="' . $styles['content'] . '">
                    ' . $body . '
                </div>
            </div>
            <div style="' . $styles['footer'] . '">
                <p>This email was sent from ' . get_bloginfo('name') . ' at ' . $date_time . '</p>
                ' . $poweredBy . '
            </div>
        </div>';
    }

    /**
     * Process email collection
     *
     * @param string $email
     * @param array $data Form data
     * @param array $providers List of providers to process
     * @return array
     */
    public function processEmailCollection($email, $data, $providers)
    {
        // Allow pre-processing
        $preResult = apply_filters('fluent_player/pre_process_email_collection', null, $email, $data, $providers);
        if ($preResult !== null) {
            return $preResult;
        }

        $results = [];

        foreach ($providers as $provider) {
            if (!Arr::isTrue($provider, 'enabled')) {
                continue;
            }

            $type = Arr::get($provider, 'type');
            $config = Arr::get($provider, 'config', []);

            // Allow modifying provider config
            $config = apply_filters('fluent_player/provider_config', $config, $type, $email, $data);

            // Process the provider
            $result = $this->processProvider($type, $email, $config);

            if (!isset($results[$type])) {
                $results[$type] = [];
            }

            $results[$type][] = $result;
        }

        // Allow post-processing
        return apply_filters('fluent_player/post_process_email_collection', $results, $email, $data, $providers);
    }
}
