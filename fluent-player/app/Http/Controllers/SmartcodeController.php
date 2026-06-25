<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\Smartcode\SmartcodeRegistry;
use FluentPlayer\Framework\Support\Arr;

class SmartcodeController extends Controller
{
    public function get()
    {
        try {
            $groups   = SmartcodeRegistry::uiGroups();
            $hasCrm   = defined('FLUENTCRM');

            if ($hasCrm && class_exists('\FluentCrm\App\Services\Helper')) {
                $crmGroups = \FluentCrm\App\Services\Helper::getGlobalSmartCodes();

                // Only contact fields — CRM's "general" group holds the
                // subscription/unsubscribe codes, which we never expose here.
                foreach ($crmGroups as $group) {
                    if (in_array(Arr::get($group, 'key'), ['contact', 'contact_custom_fields'], true)) {
                        $groups[] = $group;
                    }
                }
            }

            $groups = apply_filters('fluent_player/smartcode_groups', $groups);

            return $this->sendSuccess([
                'has_fluentcrm' => $hasCrm,
                'smartcodes'    => array_values($groups),
                'install_url'   => $hasCrm ? '' : admin_url('plugin-install.php?tab=search&type=term&s=fluentcrm'),
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Failed to load shortcodes', 'fluent-player'),
            ], 400);
        }
    }
}
