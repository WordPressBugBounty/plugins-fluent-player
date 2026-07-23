<?php

namespace FluentPlayer\App\Http\Policies;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Foundation\Policy;
use FluentPlayer\Framework\Http\Request\Request;

class LayerPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param  Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        // Layer authoring (block editor). Editors/Authors, not admin-only.
        return current_user_can(Helper::authoringCapability());
    }
}