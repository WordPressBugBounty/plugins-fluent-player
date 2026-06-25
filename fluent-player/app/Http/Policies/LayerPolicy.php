<?php

namespace FluentPlayer\App\Http\Policies;

if (!defined('ABSPATH')) exit;

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
        return current_user_can('manage_options');
    }
}