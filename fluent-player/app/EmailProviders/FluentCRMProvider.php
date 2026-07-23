<?php

namespace FluentPlayer\App\EmailProviders;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Integrations\FluentCrm\EmailCaptureProvider;
use FluentPlayer\App\Integrations\FluentCrm\Identity;

/**
 * @deprecated Back-compat shim. The CRM integration lives in
 * app/Integrations/FluentCrm (EmailCaptureProvider, Identity). Kept because
 * released Fluent Player Pro (ConditionService) references this class path
 * and its identity statics — remove once FLUENT_PLAYER_MIN_PRO_VERSION
 * guarantees the new imports.
 */
class FluentCRMProvider extends EmailCaptureProvider
{
    public static function getCurrentContact()
    {
        return Identity::getCurrentContact();
    }

    public static function resetCurrentContactCache()
    {
        Identity::resetCurrentContactCache();
    }

    public static function isCurrentUserContact()
    {
        return Identity::isCurrentUserContact();
    }

    public static function getCurrentContactEmail()
    {
        return Identity::getCurrentContactEmail();
    }
}
