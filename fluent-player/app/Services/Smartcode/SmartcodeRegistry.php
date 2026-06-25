<?php

namespace FluentPlayer\App\Services\Smartcode;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

/**
 * Single source of truth for Fluent Player shortcodes. Each namespace declares
 * its picker `tokens` (code => label) and a `resolver` callable together, so the
 * popup list and the parser cannot drift. Extend via the `fluent_player/smartcodes`
 * filter — see .claude/skills/shortcodes for the contract.
 *
 * resolver signature: function (string $key, string $fallback, array $context): string
 */
class SmartcodeRegistry
{
    public static function namespaces()
    {
        return apply_filters('fluent_player/smartcodes', self::coreNamespaces());
    }

    /**
     * Picker groups for the popup — resolvers dropped, shaped like FluentCRM's
     * getGlobalSmartCodes() ({key, title, shortcodes}).
     */
    public static function uiGroups($namespaces = null)
    {
        if ($namespaces === null) {
            $namespaces = self::namespaces();
        }

        $groups = [];

        foreach ($namespaces as $slug => $def) {
            $groupKey   = Arr::get($def, 'group', $slug);
            $groupTitle = Arr::get($def, 'group_title', ucfirst($slug));

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key'        => $groupKey,
                    'title'      => $groupTitle,
                    'shortcodes' => [],
                ];
            }

            foreach ((array) Arr::get($def, 'tokens', []) as $code => $label) {
                $groups[$groupKey]['shortcodes'][$code] = $label;
            }
        }

        return array_values($groups);
    }

    protected static function coreNamespaces()
    {
        $group      = 'general';
        $groupTitle = __('General', 'fluent-player');

        return [
            'user' => [
                'group'       => $group,
                'group_title' => $groupTitle,
                'tokens'      => [
                    '{{user.display_name}}' => __('User: Display Name', 'fluent-player'),
                    '{{user.first_name}}'   => __('User: First Name', 'fluent-player'),
                    '{{user.last_name}}'    => __('User: Last Name', 'fluent-player'),
                    '{{user.email}}'        => __('User: Email', 'fluent-player'),
                    '{{user.login}}'        => __('User: Username', 'fluent-player'),
                    '{{user.id}}'           => __('User: ID', 'fluent-player'),
                    '{{user.role}}'         => __('User: Role', 'fluent-player'),
                ],
                'resolver'    => [SmartcodeResolvers::class, 'user'],
            ],
            'site' => [
                'group'       => $group,
                'group_title' => $groupTitle,
                'tokens'      => [
                    '{{site.name}}'        => __('Site: Name', 'fluent-player'),
                    '{{site.tagline}}'     => __('Site: Tagline', 'fluent-player'),
                    '{{site.url}}'         => __('Site: URL', 'fluent-player'),
                    '{{site.admin_email}}' => __('Site: Admin Email', 'fluent-player'),
                ],
                'resolver'    => [SmartcodeResolvers::class, 'site'],
            ],
            'date' => [
                'group'       => $group,
                'group_title' => $groupTitle,
                'tokens'      => [
                    '{{date.now}}'              => __('Date: Today', 'fluent-player'),
                    '{{date.year}}'             => __('Date: Current Year', 'fluent-player'),
                    '{{date.format.D, d M Y}}'  => __('Date: Custom Format', 'fluent-player'),
                ],
                'resolver'    => [SmartcodeResolvers::class, 'date'],
            ],
            'media' => [
                'group'       => $group,
                'group_title' => $groupTitle,
                'tokens'      => [
                    '{{media.title}}'  => __('Media: Title', 'fluent-player'),
                    '{{media.author}}' => __('Media: Author', 'fluent-player'),
                    '{{media.date}}'   => __('Media: Publish Date', 'fluent-player'),
                    '{{media.id}}'     => __('Media: ID', 'fluent-player'),
                ],
                'resolver'    => [SmartcodeResolvers::class, 'media'],
            ],
        ];
    }
}
