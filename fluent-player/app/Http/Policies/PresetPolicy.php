<?php

namespace FluentPlayer\App\Http\Policies;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Foundation\Policy;

class PresetPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param  Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        // Preset reads are used while authoring media (block editor).
        return current_user_can(Helper::authoringCapability());
    }
}
