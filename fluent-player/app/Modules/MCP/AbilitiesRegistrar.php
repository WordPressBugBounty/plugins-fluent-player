<?php

namespace FluentPlayer\App\Modules\MCP;

use FluentPlayer\App\Modules\MCP\Prompts\PromptDefinitions;
use FluentPlayer\App\Modules\MCP\Support\ErrorCodes;
use FluentPlayer\App\Modules\MCP\Support\MCPHelper;
use FluentPlayer\App\Modules\MCP\Support\Mutation;
use FluentPlayer\App\Modules\MCP\Support\PermissionGate;
use FluentPlayer\App\Modules\MCP\Tools\AuditTools;
use FluentPlayer\App\Modules\MCP\Tools\ContextTools;
use FluentPlayer\App\Modules\MCP\Tools\EngagementTools;
use FluentPlayer\App\Modules\MCP\Tools\MediaTools;
use FluentPlayer\App\Modules\MCP\Tools\OverlayTools;
use FluentPlayer\App\Modules\MCP\Tools\PresetTools;
use FluentPlayer\App\Modules\MCP\Tools\SettingsTools;

class AbilitiesRegistrar
{
    const CATEGORY = 'fluent-player';

    private static function toolClasses()
    {
        return [
            ContextTools::class,
            MediaTools::class,
            PresetTools::class,
            EngagementTools::class,
            OverlayTools::class,
            SettingsTools::class,
            AuditTools::class,
        ];
    }

    /**
     * Every tool definition exposed right now, keyed by ability name.
     * Write tools are left out while "Allow AI to make changes" is off.
     */
    public static function getDefinitions()
    {
        $definitions = self::allDefinitions();

        if (PermissionGate::writesAllowed()) {
            return $definitions;
        }

        return array_filter($definitions, [self::class, 'isReadOnly']);
    }

    /** Free tools plus whatever Pro adds, regardless of the read-only switch. */
    public static function allDefinitions()
    {
        $definitions = [];
        foreach (self::toolClasses() as $class) {
            $definitions = array_merge($definitions, (array) $class::definitions());
        }

        /**
         * Filter the MCP tool definitions (ability name => definition). Fluent Player Pro adds its tools here.
         *
         * @since 1.5.0
         *
         * @param array $definitions
         */
        $filtered = apply_filters('fluent_player/mcp_tool_definitions', $definitions);

        return is_array($filtered) ? $filtered : $definitions;
    }

    public static function isReadOnly($definition)
    {
        return !empty($definition['annotations']['readonly']);
    }

    /** Display rows for the settings tab: every tool plus greyed Pro teasers when Pro is off. */
    public static function catalogue()
    {
        $rows = [];
        $writesAllowed = PermissionGate::writesAllowed();

        foreach (self::allDefinitions() as $name => $definition) {
            $isWrite = !self::isReadOnly($definition);
            $rows[$name] = [
                'name'        => $name,
                'label'       => isset($definition['label']) ? $definition['label'] : $name,
                'description' => isset($definition['description']) ? $definition['description'] : '',
                'group'       => isset($definition['group']) ? $definition['group'] : __('General', 'fluent-player'),
                'write'       => $isWrite,
                'destructive' => !empty($definition['annotations']['destructive']),
                'pro'         => !empty($definition['pro']),
                'available'   => !$isWrite || $writesAllowed,
                'teaser'      => false,
            ];
        }

        foreach (self::proToolTeasers() as $teaser) {
            if (isset($rows[$teaser['name']])) {
                continue;
            }
            $rows[$teaser['name']] = array_merge($teaser, [
                'description' => '',
                'destructive' => false,
                'pro'         => true,
                'available'   => false,
                'teaser'      => true,
            ]);
        }

        return array_values($rows);
    }

    /** Names must match Pro's real tool names; a Pro test keeps them in lockstep. */
    public static function proToolTeasers()
    {
        $analytics = __('Analytics', 'fluent-player');
        $playlists = __('Playlists', 'fluent-player');
        $captions = __('Captions', 'fluent-player');
        $timed = __('Timed Content', 'fluent-player');
        $tags = __('Tags', 'fluent-player');
        $presets = __('Presets', 'fluent-player');

        return [
            ['name' => 'fluent-player/get-analytics', 'label' => __('Get Analytics', 'fluent-player'), 'group' => $analytics, 'write' => false],
            ['name' => 'fluent-player/list-playlists', 'label' => __('List Playlists', 'fluent-player'), 'group' => $playlists, 'write' => false],
            ['name' => 'fluent-player/save-playlist', 'label' => __('Save Playlist', 'fluent-player'), 'group' => $playlists, 'write' => true],
            ['name' => 'fluent-player/list-captions', 'label' => __('List Captions', 'fluent-player'), 'group' => $captions, 'write' => false],
            ['name' => 'fluent-player/attach-subtitle', 'label' => __('Attach Caption File', 'fluent-player'), 'group' => $captions, 'write' => true],
            ['name' => 'fluent-player/remove-subtitle', 'label' => __('Remove Caption Track', 'fluent-player'), 'group' => $captions, 'write' => true],
            ['name' => 'fluent-player/list-remote-captions', 'label' => __('List YouTube/Vimeo Captions', 'fluent-player'), 'group' => $captions, 'write' => false],
            ['name' => 'fluent-player/import-remote-captions', 'label' => __('Import YouTube/Vimeo Captions', 'fluent-player'), 'group' => $captions, 'write' => true],
            ['name' => 'fluent-player/get-timed-content', 'label' => __('Get Timed Content', 'fluent-player'), 'group' => $timed, 'write' => false],
            ['name' => 'fluent-player/set-timed-content', 'label' => __('Set Timed Content', 'fluent-player'), 'group' => $timed, 'write' => true],
            ['name' => 'fluent-player/list-tags', 'label' => __('List Tags', 'fluent-player'), 'group' => $tags, 'write' => false],
            ['name' => 'fluent-player/delete-tag', 'label' => __('Delete Tag', 'fluent-player'), 'group' => $tags, 'write' => true],
            ['name' => 'fluent-player/tag-media', 'label' => __('Tag Media', 'fluent-player'), 'group' => $tags, 'write' => true],
            ['name' => 'fluent-player/save-preset', 'label' => __('Save Preset', 'fluent-player'), 'group' => $presets, 'write' => true],
            ['name' => 'fluent-player/delete-preset', 'label' => __('Delete Preset', 'fluent-player'), 'group' => $presets, 'write' => true],
        ];
    }

    /** Prompts whose tools are all live right now. */
    public static function getPromptDefinitions()
    {
        $liveTools = self::getDefinitions();

        return array_filter(PromptDefinitions::definitions(), function ($prompt) use ($liveTools) {
            foreach ($prompt['requires_tools'] as $tool) {
                if (!isset($liveTools[$tool])) {
                    return false;
                }
            }

            return true;
        });
    }

    public static function register()
    {
        foreach (self::getPromptDefinitions() as $name => $prompt) {
            $prompt['annotations'] = ['readonly' => true];
            $prompt['output_schema'] = PromptDefinitions::outputSchema();
            $args = self::abilityArgs($name, $prompt);
            if ($args) {
                // Prompt text names our server's tools; the adapter's default server has none of them.
                $args['meta']['mcp'] = ['public' => false, 'type' => 'prompt'];
                wp_register_ability($name, $args);
            }
        }

        foreach (self::getDefinitions() as $name => $definition) {
            $args = self::abilityArgs($name, $definition);
            if ($args) {
                wp_register_ability($name, $args);
            }
        }
    }

    /**
     * Ability registration args for one definition, or null when it has no callback or permission source.
     *
     * @return array|null
     */
    public static function abilityArgs($name, $definition)
    {
        $permissionCallback = self::permissionCallback($definition);
        if (empty($definition['execute_callback']) || !$permissionCallback) {
            return null;
        }

        $readOnly = self::isReadOnly($definition);
        $annotations = isset($definition['annotations']) ? (array) $definition['annotations'] : [];

        $args = [
            'label'               => isset($definition['label']) ? $definition['label'] : $name,
            'description'         => isset($definition['description']) ? $definition['description'] : '',
            'category'            => self::CATEGORY,
            'execute_callback'    => self::wrapExecuteCallback($name, $definition['execute_callback'], isset($definition['input_schema']) ? $definition['input_schema'] : [], $readOnly),
            // Core's /wp-abilities routes and the adapter's default server never run transport() themselves.
            'permission_callback' => function ($input = null) use ($permissionCallback) {
                return PermissionGate::transport() ? call_user_func($permissionCallback, $input) : false;
            },
            'output_schema'       => isset($definition['output_schema']) ? $definition['output_schema'] : MCPHelper::envelopeSchema(),
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => $readOnly,
                    'destructive' => $readOnly ? false : !empty($annotations['destructive']),
                    'idempotent'  => $readOnly ? true : !empty($annotations['idempotent']),
                ],
            ],
        ];

        $inputSchema = isset($definition['input_schema']) ? $definition['input_schema'] : [];
        if ($inputSchema) {
            if (isset($inputSchema['type']) && 'object' === $inputSchema['type']) {
                if (!isset($inputSchema['additionalProperties'])) {
                    $inputSchema['additionalProperties'] = false;
                }
                // Agents often send no arguments at all to a tool whose inputs are all optional.
                if (empty($inputSchema['required']) && !array_key_exists('default', $inputSchema)) {
                    $inputSchema['default'] = [];
                }
            }
            $args['input_schema'] = $inputSchema;
        }

        return $args;
    }

    /**
     * An explicit permission_callback wins; otherwise 'capability' (one cap or an any-of list).
     *
     * @return callable|null
     */
    public static function permissionCallback($definition)
    {
        if (!empty($definition['permission_callback'])) {
            return $definition['permission_callback'];
        }

        if (empty($definition['capability'])) {
            return null;
        }

        $capabilities = (array) $definition['capability'];

        return function () use ($capabilities) {
            foreach ($capabilities as $capability) {
                if (current_user_can($capability)) {
                    return true;
                }
            }

            return false;
        };
    }

    private static function wrapExecuteCallback($toolName, $callback, $inputSchema, $readOnly)
    {
        return function ($params = []) use ($toolName, $callback, $inputSchema, $readOnly) {
            $params = is_array($params) ? $params : [];
            // Core validates loosely ("false" passes a boolean) but never coerces; sanitize to real types.
            if ($inputSchema) {
                $sanitized = rest_sanitize_value_from_schema($params, $inputSchema, 'input');
                $params = is_array($sanitized) ? $sanitized : $params;
            }

            if (!$readOnly && ($replay = Mutation::replay($toolName, $params))) {
                return $replay;
            }

            try {
                return call_user_func($callback, $params);
            } catch (\Throwable $e) {
                // The message can carry SQL or file paths, so only debug sites log it; a write may have partly applied, so it is not retried blindly.
                $reason = get_class($e);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $reason .= ': ' . $e->getMessage();
                }
                error_log(sprintf('Fluent Player MCP tool %s failed: %s', $toolName, $reason)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                return MCPHelper::error(ErrorCodes::TOOL_FAILED, $readOnly
                    ? __('The tool failed unexpectedly. Try again.', 'fluent-player')
                    : __('The change failed unexpectedly and may be partly applied. Check the current state with a read tool before trying again.', 'fluent-player'), [
                    'tool'      => $toolName,
                    'retryable' => $readOnly,
                ]);
            }
        };
    }
}
