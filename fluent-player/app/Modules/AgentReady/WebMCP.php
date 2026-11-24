<?php

namespace FluentPlayer\App\Modules\AgentReady;

use FluentPlayer\Framework\Support\Arr;

/**
 * Lets agentic browsers (WebMCP) drive opted-in players on public pages.
 *
 * Kept apart from App\Modules\MCP: that is an authenticated server endpoint, this is
 * public markup for anonymous visitors. Like Fluent Forms, each video opts in on its own;
 * the public payload carries `webmcp: true` only then, and the browser tools load only for
 * such players.
 *
 * @see https://github.com/webmachinelearning/webmcp
 */
class WebMCP
{
    /** Tool names already printed in this request, so a repeated video gets its own name. */
    private static $issuedToolNames = [];

    /**
     * @param array $settings Media settings as rendered.
     */
    public static function isEnabledFor($settings)
    {
        return self::isSwitchOn(Arr::get((array) $settings, 'webmcp'));
    }

    /**
     * Strict reading of the per-video switch: 'no'/'false' strings, which (bool) and
     * rest_sanitize_boolean() read as on, stay off.
     */
    public static function isSwitchOn($value)
    {
        return in_array($value, [true, 1, '1', 'true', 'yes'], true);
    }

    /**
     * The per-video switch as the browser should see it: strictly true or absent.
     *
     * @param array $settings Media settings bound for a public payload.
     * @return array
     */
    public static function normalizeForFrontend(array $settings)
    {
        if (self::isEnabledFor($settings)) {
            $settings['webmcp'] = true;
        } else {
            unset($settings['webmcp']);
        }

        return $settings;
    }

    /**
     * Declarative tool attributes for an email signup form; empty when not opted in.
     * No toolautosubmit: the agent fills the field, the visitor presses the button.
     *
     * @param array  $settings Media settings as rendered.
     * @param int    $mediaId
     * @param string $layerId  Set for an email layer, so each form on a page gets its own tool name.
     */
    public static function emailFormAttributes($settings, $mediaId, $layerId = '')
    {
        if (!self::isEnabledFor($settings)) {
            return '';
        }

        $baseName = 'fluent_player_email_signup_' . absint($mediaId) . ($layerId !== '' ? '_' . sanitize_key($layerId) : '');
        $count = isset(self::$issuedToolNames[$baseName]) ? self::$issuedToolNames[$baseName] + 1 : 1;
        self::$issuedToolNames[$baseName] = $count;
        $toolName = $count > 1 ? $baseName . '_' . $count : $baseName;
        $description = __('Fill in the email signup shown in this video player. The visitor reviews it and presses the button to submit.', 'fluent-player');

        // novalidate keeps the plugin's own email error message once the button is a real submit.
        return ' toolname="' . esc_attr($toolName) . '" tooldescription="' . esc_attr($description) . '" novalidate';
    }

    /**
     * Browsers only accept a fill-only form tool that has a submit button; the form's
     * submit event then runs the signup.
     *
     * @param array $settings Media settings as rendered.
     */
    public static function emailButtonType($settings)
    {
        return self::isEnabledFor($settings) ? 'submit' : 'button';
    }

    /**
     * @param array $settings Media settings as rendered.
     */
    public static function emailInputAttributes($settings)
    {
        if (!self::isEnabledFor($settings)) {
            return '';
        }

        return ' name="email" toolparamdescription="' . esc_attr__('The visitor’s email address.', 'fluent-player') . '"';
    }
}
