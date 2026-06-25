<?php

namespace FluentPlayer\App\Http\Policies;

use FluentPlayer\Framework\Foundation\Policy;
use FluentPlayer\Framework\Http\Request\Request;

class MigrationPolicy extends Policy
{
    /**
     * Check if the current user can access migration tools
     *
     * @param  \FluentPlayer\Framework\Http\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        return current_user_can('manage_options');
    }
}
