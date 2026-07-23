<?php

namespace FluentPlayer\App\Http\Policies;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Foundation\Policy;

class MediaPolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        // Media authoring (block editor). Editors/Authors, not admin-only.
        return current_user_can(Helper::authoringCapability());
    }
}