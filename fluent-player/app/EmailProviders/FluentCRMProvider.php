<?php

namespace FluentPlayer\App\EmailProviders;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class FluentCRMProvider extends AbstractEmailProvider
{
    protected $provider = 'fluentcrm';
    protected $name = 'FluentCRM';
    protected $description = 'Connect FluentPlayer with FluentCRM to add email subscribers to your lists and tags.';
    protected $logo = 'fluentcrm.svg';

    protected $defaultSettings = [
        'enabled' => false,
        'connected' => false,
        'connectUrl' => '',
        'lists' => [],
        'tags' => [],
        'double_optin' => false
    ];

    /**
     * Resolve the current FluentCRM contact from user or secure cookie.
     * Result is cached per request.
     *
     * @return mixed
     */
    public static function getCurrentContact()
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        if (!defined('FLUENTCRM')) {
            $cache = false;
            return false;
        }

        try {
            $contact = FluentCrmApi('contacts')->getCurrentContact(true, true);
            $cache = !empty($contact) ? $contact : false;
        } catch (\Exception $e) {
            $cache = false;
        }

        return $cache;
    }

    /**
     * Check if the current logged-in user is an existing FluentCRM contact.
     * Result is cached per request.
     *
     * @return bool
     */
    public static function isCurrentUserContact()
    {
        return !empty(self::getCurrentContact());
    }

    /**
     * Get the current FluentCRM contact email if available.
     *
     * @return string
     */
    public static function getCurrentContactEmail()
    {
        $contact = self::getCurrentContact();
        if (empty($contact->email)) {
            return '';
        }

        return sanitize_email($contact->email);
    }

    /**
     * Validate settings before saving
     * @param array $settings
     * @return array|\WP_Error
     */
    public function validateSettings($settings)
    {
        // No specific validation needed as we just check if FluentCRM is installed
        return $settings;
    }

    /**
     * Sanitize settings before saving
     * @param array $settings
     * @return array
     */
    public function sanitizeSettings($settings)
    {
        $sanitized = Sanitizer::sanitize($settings, [
            'enabled' => 'rest_sanitize_boolean',
            'connected' => 'rest_sanitize_boolean',
            'connectUrl' => 'escUrlRaw',
            'lists.*' => 'intval',
            'tags.*' => 'intval',
            'double_optin' => 'rest_sanitize_boolean'
        ]);

        // Check if FluentCRM plugin exists
        $fluentCrmExists = defined('FLUENTCRM');

        if (!$fluentCrmExists) {
            $sanitized['enabled'] = false;
            $sanitized['connected'] = false;
            $sanitized['connectUrl'] = admin_url('plugin-install.php?tab=search&type=term&s=fluentcrm');
        } else {
            $sanitized['enabled'] = true;
            $sanitized['connected'] = true;
        }

        return $sanitized;
    }

    /**
     * Get provider settings fields configuration
     * @return array
     */
    public function getSettingsFields()
    {
        return [
            [
                'key' => 'lists',
                'type' => 'select',
                'label' => __('Lists', 'fluent-player'),
                'multiple' => true,
                'placeholder' => __('Select lists', 'fluent-player'),
                'async' => true,
                'endpoint' => 'email-providers/fluentcrm/lists',
                'help' => __('Select the lists you want to add subscribers to', 'fluent-player'),
                'context' => 'preset_editor'
            ],
            [
                'key' => 'tags',
                'type' => 'select',
                'label' => __('Tags', 'fluent-player'),
                'multiple' => true,
                'placeholder' => __('Select tags', 'fluent-player'),
                'async' => true,
                'endpoint' => 'email-providers/fluentcrm/tags',
                'help' => __('Select the tags you want to assign to subscribers', 'fluent-player'),
                'context' => 'preset_editor'
            ],
            [
                'key' => 'double_optin',
                'type' => 'toggle',
                'label' => __('Enable double opt-in', 'fluent-player'),
                'help' => __('Create the contact as pending and send FluentCRM confirmation email before treating them as subscribed.', 'fluent-player'),
                'default' => false,
                'context' => 'preset_editor'
            ]
        ];
    }

    /**
     * Subscribe an email address to FluentCRM
     * @param string $email
     * @param array $data Additional data
     * @param array $settings Provider settings
     * @return array|\WP_Error
     */
    public function subscribe($email, $data, $settings)
    {
        if (!defined('FLUENTCRM')) {
            return new \WP_Error('missing_plugin', __('FluentCRM plugin is not installed or activated', 'fluent-player'));
        }

        try {
            $lists = Arr::get($settings, 'lists', []);
            $tags = Arr::get($settings, 'tags', []);
            $doubleOptin = Arr::isTrue($settings, 'double_optin');

            $contactData = [
                'email' => $email,
                'first_name' => Arr::get($data, 'first_name', ''),
                'last_name' => Arr::get($data, 'last_name', ''),
                'status' => $doubleOptin ? 'pending' : 'subscribed'
            ];

            // Pre-fetch only when double opt-in is off: existing non-subscribed contacts
            // must be explicitly upgraded; createOrUpdate won't change status on its own.
            // When double opt-in is on, $forceSubscribed is always false — skip the query.
            $forceSubscribed = false;
            if (!$doubleOptin) {
                $existingContact = FluentCrmApi('contacts')->getContact($email);
                $forceSubscribed = $existingContact && $existingContact->status !== 'subscribed';
            }

            // Create or update contact
            $contact = FluentCrmApi('contacts')->createOrUpdate($contactData, $forceSubscribed, false);

            if ($contact) {
                if (!empty($lists)) {
                    $contact->attachLists($lists);
                }

                // Add tags
                if (!empty($tags)) {
                    $contact->attachTags($tags);
                }

                $requiresConfirmation = $doubleOptin && in_array($contact->status, ['pending', 'unsubscribed'], true);

                if ($requiresConfirmation) {
                    $contact->sendDoubleOptinEmail();
                }

                return [
                    'success' => true,
                    'message' => $requiresConfirmation
                        ? __('Confirmation email sent via FluentCRM', 'fluent-player')
                        : __('Contact added to FluentCRM successfully', 'fluent-player'),
                    'contact_id' => $contact->id,
                    'status' => $contact->status
                ];
            }

            return new \WP_Error('subscription_failed', __('Failed to create contact in FluentCRM', 'fluent-player'));
        } catch (\Exception $e) {
            return new \WP_Error('subscription_failed', $e->getMessage());
        }
    }

    /**
     * Check if provider is properly configured
     * @param array $settings
     * @return bool
     */
    public function isConfigured($settings)
    {
        return defined('FLUENTCRM');
    }

    /**
     * Handle provider-specific actions
     * @param string $action
     * @param array $settings
     * @param array $data
     * @return array|\WP_Error
     */
    public function handleAction($action, $settings, $data = [])
    {
        if ($action === 'lists') {
            return $this->getLists();
        } elseif ($action === 'tags') {
            return $this->getTags();
        }

        return parent::handleAction($action, $settings, $data);
    }

    /**
     * Get FluentCRM lists
     * @return array
     */
    protected function getLists()
    {
        if (!defined('FLUENTCRM')) {
            return [
                'success' => false,
                'message' => __('FluentCRM is not installed or activated', 'fluent-player'),
                'lists' => []
            ];
        }

        try {
            $lists = [];

            if (class_exists('\FluentCrm\App\Models\Lists')) {
                $fluentLists = \FluentCrm\App\Models\Lists::select(['id', 'title'])
                    ->orderBy('title', 'asc')
                    ->get();

                foreach ($fluentLists as $list) {
                    $lists[] = [
                        'id' => $list->id,
                        'name' => $list->title
                    ];
                }
            }

            return [
                'success' => true,
                'lists' => $lists
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'lists' => []
            ];
        }
    }

    /**
     * Get FluentCRM tags
     * @return array
     */
    protected function getTags()
    {
        if (!defined('FLUENTCRM')) {
            return [
                'success' => false,
                'message' => __('FluentCRM is not installed or activated', 'fluent-player'),
                'tags' => []
            ];
        }

        try {
            $tags = [];

            if (class_exists('\FluentCrm\App\Models\Tag')) {
                $fluentTags = \FluentCrm\App\Models\Tag::select(['id', 'title'])
                    ->orderBy('title', 'asc')
                    ->get();

                foreach ($fluentTags as $tag) {
                    $tags[] = [
                        'id' => $tag->id,
                        'name' => $tag->title
                    ];
                }
            }

            return [
                'success' => true,
                'tags' => $tags
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'tags' => []
            ];
        }
    }

    /**
     * Verify the connection status
     *
     * @param array $settings
     * @return array Updated settings
     */
    public function verifyConnectionStatus($settings)
    {
        $fluentCrmExists = defined('FLUENTCRM');
        $settings['connected'] = $fluentCrmExists;

        if (!$fluentCrmExists) {
            $settings['enabled'] = false;
            $settings['connectUrl'] = admin_url('plugin-install.php?tab=search&type=term&s=fluentcrm');
        } else {
            $settings['enabled'] = true;
        }

        return $settings;
    }
}
