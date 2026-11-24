<?php

namespace FluentPlayer\App\Modules\MCP\Support;

use FluentPlayer\App\Helpers\Helper;

class PermissionGate
{
    const OPTION = '_fluent_player_mcp_settings';

    public static function isEnabled()
    {
        return 'yes' === self::setting('enabled');
    }

    /** When off, write tools are not registered at all. */
    public static function writesAllowed()
    {
        return 'yes' === self::setting('allow_writes');
    }

    /** @return true|\WP_Error */
    public static function setEnabled($enabled)
    {
        return self::saveSetting('enabled', $enabled);
    }

    /** @return true|\WP_Error */
    public static function setWritesAllowed($allowed)
    {
        return self::saveSetting('allow_writes', $allowed);
    }

    /**
     * Gate for the whole MCP endpoint; each ability still runs its own permission check.
     * A plain false: the adapter logs every WP_Error, so returning one let anonymous requests fill the error log.
     *
     * @return bool
     */
    public static function transport($request = null)
    {
        return self::isEnabled() && is_user_logged_in() && self::canAuthor();
    }

    public static function canAuthor()
    {
        return current_user_can(Helper::authoringCapability());
    }

    public static function canManage()
    {
        return current_user_can('manage_options');
    }

    private static function settings()
    {
        $settings = get_option(self::OPTION, []);

        return is_array($settings) ? $settings : [];
    }

    private static function setting($key)
    {
        $settings = self::settings();

        return isset($settings[$key]) ? $settings[$key] : 'no';
    }

    // FluentHub counts a plain false as success, so refusals must be WP_Error.
    private static function saveSetting($key, $value)
    {
        if (!self::canManage()) {
            return new \WP_Error('fluent_player_mcp_forbidden', __('You do not have permission to change MCP settings.', 'fluent-player'), ['status' => 403]);
        }

        $settings = self::settings();
        $settings[$key] = $value ? 'yes' : 'no';
        update_option(self::OPTION, $settings, true);

        return true;
    }
}
