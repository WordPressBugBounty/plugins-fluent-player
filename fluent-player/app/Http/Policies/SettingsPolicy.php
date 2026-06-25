<?php

namespace FluentPlayer\App\Http\Policies;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Foundation\Policy;
use FluentPlayer\Framework\Http\Request\Request;

class SettingsPolicy extends Policy
{
    /**
     * Check if the current user can access settings
     *
     * @param  \FluentPlayer\Framework\Http\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        return current_user_can('manage_options');
    }
}
