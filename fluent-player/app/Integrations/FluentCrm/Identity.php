<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

/**
 * Resolves the current FluentCRM contact (logged-in user or the secure
 * first-party cookie) with a single per-request cache shared by every
 * consumer: email capture, behavior handlers, timeline bridge, and Pro.
 */
class Identity
{
    private static $currentContactCache = null;

    /**
     * @return mixed contact object or false
     */
    public static function getCurrentContact()
    {
        if (self::$currentContactCache !== null) {
            return self::$currentContactCache;
        }

        if (!defined('FLUENTCRM')) {
            self::$currentContactCache = false;
            return false;
        }

        try {
            // $cached=false: this class is the per-request cache; FluentCRM's own
            // function-static would otherwise leak across identity changes
            $contact = FluentCrmApi('contacts')->getCurrentContact(false, true);
            self::$currentContactCache = !empty($contact) ? $contact : false;
        } catch (\Exception $e) {
            self::$currentContactCache = false;
        }

        return self::$currentContactCache;
    }

    public static function resetCurrentContactCache()
    {
        self::$currentContactCache = null;
    }

    /**
     * @return bool
     */
    public static function isCurrentUserContact()
    {
        return !empty(self::getCurrentContact());
    }

    /**
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
}
