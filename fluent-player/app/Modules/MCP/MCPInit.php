<?php

namespace FluentPlayer\App\Modules\MCP;

use FluentPlayer\App\Modules\MCP\Support\PermissionGate;

class MCPInit
{
    const SERVER_ID = 'fluent-player';

    const ROUTE_NAMESPACE = 'fluent-player';

    const ROUTE = 'mcp';

    /** Runs on every request; FluentHub discovery stays on even while MCP is off. */
    public static function boot()
    {
        self::registerWithToolkit();

        if (PermissionGate::isEnabled()) {
            add_action('wp_abilities_api_categories_init', [self::class, 'registerCategory']);
            add_action('wp_abilities_api_init', [AbilitiesRegistrar::class, 'register']);
            add_action('mcp_adapter_init', [self::class, 'registerServer']);
        }
    }

    public static function registerCategory()
    {
        wp_register_ability_category(AbilitiesRegistrar::CATEGORY, [
            'label'       => __('Fluent Player', 'fluent-player'),
            'description' => __('Media, playlists, presets and analytics for Fluent Player.', 'fluent-player'),
        ]);
    }

    /** @param object $adapter \WP\MCP\Core\McpAdapter */
    public static function registerServer($adapter)
    {
        if (!is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }

        $adapter->create_server(
            self::SERVER_ID,
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            __('Fluent Player MCP Server', 'fluent-player'),
            __('AI agent tools for Fluent Player media, playlists, presets and analytics.', 'fluent-player'),
            defined('FLUENT_PLAYER_VERSION') ? FLUENT_PLAYER_VERSION : '1.0.0',
            ['\WP\MCP\Transport\HttpTransport'],
            '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
            '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
            array_keys(AbilitiesRegistrar::getDefinitions()),
            [],
            array_keys(AbilitiesRegistrar::getPromptDefinitions()),
            [PermissionGate::class, 'transport']
        );
    }

    public static function registerWithToolkit()
    {
        add_filter('fluent_kit/mcp_products', function ($products) {
            $products = is_array($products) ? $products : [];
            $products[] = [
                'slug'         => self::SERVER_ID,
                'name'         => __('Fluent Player', 'fluent-player'),
                'mcp_enabled'  => PermissionGate::isEnabled(),
                'tools_count'  => count(AbilitiesRegistrar::getDefinitions()),
                'endpoint_url' => self::getEndpointUrl(),
                'status'       => self::toolkitStatus(),
            ];

            return $products;
        });

        add_filter('fluent_kit/mcp_toggle_handlers', function ($handlers) {
            $handlers = is_array($handlers) ? $handlers : [];
            $handlers[self::SERVER_ID] = [
                'get_enabled' => [PermissionGate::class, 'isEnabled'],
                'set_enabled' => [PermissionGate::class, 'setEnabled'],
            ];

            return $handlers;
        });
    }

    /** FluentHub only understands ready, disabled and adapter_required. */
    public static function toolkitStatus()
    {
        if (!self::adapterAvailable()) {
            return 'adapter_required';
        }

        return PermissionGate::isEnabled() ? 'ready' : 'disabled';
    }

    public static function getEndpointUrl()
    {
        return get_rest_url(null, self::ROUTE_NAMESPACE . '/' . self::ROUTE);
    }

    public static function abilitiesApiAvailable()
    {
        return function_exists('wp_register_ability');
    }

    public static function adapterAvailable()
    {
        return defined('WP_MCP_VERSION')
            && class_exists('\WP\MCP\Core\McpAdapter')
            && self::abilitiesApiAvailable();
    }
}
