<?php

namespace FluentPlayer\App\Http\Policies;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Foundation\Policy;

/**
 * Public by design — the viewer is usually a guest. Authorization is the signed grant plus
 * the access re-checks in PlaybackController, enforced there so a refusal is an explicit
 * 403 with a body rather than the framework's generic permission-callback error.
 */
class PlaybackPolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return true;
    }
}
