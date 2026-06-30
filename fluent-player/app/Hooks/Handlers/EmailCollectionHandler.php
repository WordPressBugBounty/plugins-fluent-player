<?php

namespace FluentPlayer\App\Hooks\Handlers;
if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;
use FluentPlayer\App\Models\EmailCollection;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\EmailCollectionService;
use FluentPlayer\App\Services\PresetService;
use FluentPlayer\App\Utils\Browser\Browser;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class EmailCollectionHandler
{
    /**
     * @var \FluentPlayer\Framework\Foundation\Application
     */
    protected $app;

    /**
     * @var EmailCollectionService
     */
    protected $collectionService;

    /**
     * @var Browser
     */
    protected $browser;

    /**
     * EmailCollectionHandler constructor.
     */
    public function __construct()
    {
        $this->app = App::make();
        $this->collectionService = new EmailCollectionService();
    }

    // Lazy: the handler runs on every `init`; only email-submit requests need the UA parse.
    protected function browser()
    {
        if ($this->browser === null) {
            $this->browser = new Browser();
        }

        return $this->browser;
    }

    /**
     * Register AJAX hooks
     */
    public function handle()
    {
        // Register default AJAX handler
        add_action('wp_ajax_fluent_player_email_submit', [$this, 'submit']);
        add_action('wp_ajax_nopriv_fluent_player_email_submit', [$this, 'submit']);

        // Allow external code to register additional hooks
        do_action('fluent_player/email_collection_hooks', $this);
    }

    /**
     * Handle a new email submission from the frontend
     */
    public function submit()
    {
        try {
            // Get and validate data
            $data = $this->getRawRequestData();
            $this->validateRequestData($data);

            $type = Arr::get($data, 'type', 'preset');

            // Allow pre-processing or early termination
            $preProcessResult = apply_filters('fluent_player/pre_process_email_submit', null, $data);
            if ($preProcessResult !== null) {
                wp_send_json_success($preProcessResult);
                return;
            }

            $settings = $this->resolveCaptureSettings($data, $type);
            $existing = $this->findExistingSubmission($data, $type);

            $this->enforceGuestSubmissionRateLimit($data);

            if ($existing) {
                $this->refreshExistingSubmission($existing, $data);
                $integrationResults = $this->getExistingIntegrationResults($existing);

                do_action('fluent_player/email_collected', $data, $existing, false, $integrationResults);

                wp_send_json_success([
                    'message' => __('Email collected successfully', 'fluent-player')
                ]);
                return;
            }

            // Process providers
            $providers = Arr::get($settings, 'email_capture.providers', []);

            $providers = apply_filters('fluent_player/email_providers', $providers, $data, $settings);

            // Process email collection
            $integrationResults = $this->collectionService->processEmailCollection(
                $data['email'],
                $data,
                $providers
            );

            $created = true;
            $submissionData = $this->prepareSubmissionData($data, $integrationResults);

            // Allow modification of submission data
            $submissionData = apply_filters('fluent_player/submission_data', $submissionData, $data, $integrationResults);

            // Create new submission
            $submission = EmailCollection::create($submissionData);

            // Trigger action
            do_action('fluent_player/email_collected', $data, $submission, $created, $integrationResults);

            $responseData = $this->getSuccessResponseData($integrationResults);

            // Send response
            wp_send_json_success($responseData);

        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Error logging for debugging
                error_log('Error processing email submission: ' . $e->getMessage());
            }

            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 500;
            }

            wp_send_json_error([
                'message' => $e->getMessage()
            ], $statusCode);
        }
    }

    protected function resolveCaptureSettings($data, $type)
    {
        if ('preset' == $type) {
            $presetData = PresetService::find(Arr::get($data, 'preset_slug'));
            if (!$presetData || !isset($presetData['settings'])) {
                throw new \Exception(esc_html__('Invalid preset', 'fluent-player'));
            }

            return $presetData['settings'];
        }

        if ('layer' == $type) {
            $mediaSettings = Media::getMediaSettings($data['media_id']);
            if (!$mediaSettings) {
                throw new \Exception(esc_html__('Invalid layer', 'fluent-player'));
            }

            $layers = Arr::get($mediaSettings, 'layers', []);
            if (!$layers) {
                throw new \Exception(esc_html__('Invalid layer', 'fluent-player'));
            }

            $layers = array_column($layers, null, 'id');
            $layer = Arr::get($layers, $data['layer_id'], []);
            if (!$layer) {
                throw new \Exception(esc_html__('Invalid layer', 'fluent-player'));
            }

            return $layer;
        }

        throw new \Exception(esc_html__('Invalid type', 'fluent-player'));
    }

    protected function findExistingSubmission($data, $type)
    {
        return EmailCollection::where('email', $data['email'])
            ->when('preset' == $type, function ($query) use ($data) {
                $query->where('preset_slug', Arr::get($data, 'preset_slug'))
                      ->where('media_id', Arr::get($data, 'media_id'));
            })
            ->when('layer' == $type, function ($query) use ($data) {
                $query->where('media_id', Arr::get($data, 'media_id'))
                      ->where('layer_id', $data['layer_id']);
            })
            ->first();
    }

    protected function prepareSubmissionData($data, $integrationResults)
    {
        return [
            'email'        => Arr::get($data, 'email'),
            'media_id'     => Arr::get($data, 'media_id'),
            'preset_slug'  => Arr::get($data, 'preset_slug'),
            'layer_id'     => Arr::get($data, 'layer_id'),
            'video_time'   => Arr::get($data, 'video_time'),
            'user_id'      => Arr::get($data, 'user_id'),
            'ip_address'   => Arr::get($data, 'ip_address'),
            'browser'      => Arr::get($data, 'browser'),
            'device'       => Arr::get($data, 'device'),
            'meta'         => ['provider_log' => $integrationResults],
            'created_at'   => current_time('mysql')
        ];
    }

    /**
     * Get all request data
     *
     * @return array
     */
    protected function getRawRequestData()
    {
        $rawData = [
            'email'      => $this->app->request->get('email'),
            'media_id'   => $this->app->request->get('media_id'),
            'preset_slug' => $this->app->request->get('preset_slug'),
            'layer_id'   => $this->app->request->get('layer_id'),
            'type'       => $this->app->request->get('type', 'preset'),
            'video_time' => $this->app->request->get('video_time', 0),
            'nonce'      => $this->app->request->get('nonce'),
            'user_id'    => get_current_user_id() ?: null,
            'ip_address' => $this->app->request->getIp(),
            'browser'    => $this->browser()->getBrowser(),
            'device'     => $this->browser()->getPlatform(),
        ];

        $rawData = Sanitizer::sanitize($rawData, [
            'email'      => 'sanitizeEmail',
            'media_id'   => 'intval',
            'preset_slug' => 'sanitizeTextField',
            'layer_id'   => 'sanitizeTextField',
            'type'       => 'sanitizeTextField',
            'video_time' => 'floatval',
            'nonce'      => 'sanitizeTextField',
            'user_id'    => 'intval',
            'ip_address' => 'sanitizeTextField',
            'browser'    => 'sanitizeTextField',
            'device'     => 'sanitizeTextField',
        ]);

        // Allow modification of raw data
        return apply_filters('fluent_player/raw_request_data', $rawData);
    }

    /**
     * Validate request data
     *
     * @param array $data
     * @throws \Exception
     */
    protected function validateRequestData($data)
    {
        // Allow custom validation to run first
        $customValidation = apply_filters('fluent_player/validate_email_submission', null, $data);
        if ($customValidation instanceof \WP_Error) {
            throw new \Exception(\esc_html($customValidation->get_error_message()));
        }

        // Validate email
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception(\esc_html(__('Invalid email address', 'fluent-player')));
        }

        if (empty($data['media_id'])) {
            throw new \Exception(\esc_html(__('Media ID is required', 'fluent-player')));
        }

        // Validate preset slug
        if ('preset' == Arr::get($data, 'type') && empty($data['preset_slug'])) {
            throw new \Exception(\esc_html(__('Preset is required', 'fluent-player')));
        }

        // Validate layer ID
        if ('layer' == Arr::get($data, 'type') && empty($data['layer_id'])) {
            throw new \Exception(\esc_html(__('Layer ID is required', 'fluent-player')));
        }

        $expectedNonceAction = EmailCollectionService::getNonceAction(
            Arr::get($data, 'type', 'preset'),
            Arr::get($data, 'media_id'),
            Arr::get($data, 'preset_slug', ''),
            Arr::get($data, 'layer_id', '')
        );

        if (!wp_verify_nonce($data['nonce'], $expectedNonceAction)) {
            throw new \Exception(\esc_html(__('Invalid request', 'fluent-player')));
        }
    }

    /**
     * Update the existing submission context without retriggering providers.
     *
     * @param EmailCollection $existing
     * @param array $data
     * @return void
     */
    protected function refreshExistingSubmission($existing, $data)
    {
        $existing->ip_address = Arr::get($data, 'ip_address');
        $existing->browser = Arr::get($data, 'browser');
        $existing->device = Arr::get($data, 'device');
        $existing->updated_at = current_time('mysql');
        $existing->save();
    }

    /**
     * Retrieve the last known provider log for an existing submission.
     *
     * @param EmailCollection $existing
     * @return array
     */
    protected function getExistingIntegrationResults($existing)
    {
        $meta = $existing->meta;
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }

        $providerLog = Arr::get($meta, 'provider_log', []);

        return is_array($providerLog) ? $providerLog : [];
    }

    /**
     * Limit guest-side submissions per target to reduce bulk-abuse potential.
     *
     * @param array $data
     * @throws \Exception
     * @return void
     */
    protected function enforceGuestSubmissionRateLimit($data)
    {
        if (is_user_logged_in()) {
            return;
        }

        $maxAttempts = absint(apply_filters(
            'fluent_player/email_submission_rate_limit_max_attempts',
            3,
            $data
        ));
        $window = absint(apply_filters(
            'fluent_player/email_submission_rate_limit_window',
            5 * MINUTE_IN_SECONDS,
            $data
        ));

        if ($maxAttempts < 1 || $window < 1) {
            return;
        }

        $ipAddress = trim((string) Arr::get($data, 'ip_address', ''));
        if (!$ipAddress) {
            return;
        }

        $keyData = [
            'ip'          => $ipAddress,
            'type'        => Arr::get($data, 'type', 'preset'),
            'media_id'    => absint(Arr::get($data, 'media_id')),
            'preset_slug' => (string) Arr::get($data, 'preset_slug', ''),
            'layer_id'    => (string) Arr::get($data, 'layer_id', ''),
        ];

        $transientKey = 'flp_email_rl_' . md5(wp_json_encode($keyData));
        $attempts = get_transient($transientKey);

        if (!is_array($attempts)) {
            $attempts = [];
        }

        $cutoff = time() - $window;
        $attempts = array_values(array_filter($attempts, function ($timestamp) use ($cutoff) {
            return is_numeric($timestamp) && (int) $timestamp > $cutoff;
        }));

        if (count($attempts) >= $maxAttempts) {
            throw new \Exception(
                \esc_html(__('Too many email submissions. Please try again later.', 'fluent-player')),
                429
            );
        }

        $attempts[] = time();
        set_transient($transientKey, $attempts, $window);
    }

    /**
     * Build frontend success response data from provider results.
     *
     * @param array $integrationResults
     * @return array
     */
    protected function getSuccessResponseData($integrationResults)
    {
        $pendingConfirmation = false;
        foreach ((array) $integrationResults as $providerResults) {
            foreach ((array) $providerResults as $result) {
                if (Arr::get($result, 'success') && Arr::get($result, 'data.status') === 'pending') {
                    $pendingConfirmation = true;
                    break 2;
                }
            }
        }

        return [
            'message' => $pendingConfirmation
                ? __('Please check your inbox to confirm your email address.', 'fluent-player')
                : __('Email collected successfully', 'fluent-player'),
        ];
    }
}
