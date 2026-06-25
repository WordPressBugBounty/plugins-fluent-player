<?php

namespace FluentPlayer\App\Http\Policies;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Foundation\Policy;

class MediaPolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return current_user_can('manage_options');
    }
}