<?php

namespace FluentPlayer\App\Http\Controllers;

use FluentPlayer\App\Modules\MCP\AbilitiesRegistrar;
use FluentPlayer\App\Modules\MCP\MCPInit;
use FluentPlayer\App\Modules\MCP\Support\ActivityLog;
use FluentPlayer\App\Modules\MCP\Support\PermissionGate;
use FluentPlayer\Framework\Http\Request\Request;

class McpSettingsController extends Controller
{
    const TOOLKIT_PLUGIN_FILE = 'fluent-toolkit/fluent-toolkit.php';

    const ADAPTER_PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

    const TOOLKIT_DOWNLOAD_URL = 'https://github.com/WPManageNinja/fluent-toolkit';

    public function status()
    {
        if (!current_user_can('manage_options')) {
            return $this->forbidden();
        }

        $user = wp_get_current_user();

        return $this->sendSuccess([
            'mcp_enabled'          => PermissionGate::isEnabled(),
            'writes_allowed'       => PermissionGate::writesAllowed(),
            'wp_supported'         => MCPInit::abilitiesApiAvailable(),
            'adapter_available'    => MCPInit::adapterAvailable(),
            'adapter_installed'    => $this->isToolkitInstalled() || $this->isPluginInstalled(self::ADAPTER_PLUGIN_FILE),
            'toolkit_installed'    => $this->isToolkitInstalled(),
            'fluenthub_active'     => defined('FLUENT_TOOLKIT_VERSION'),
            'fluenthub_mcp_url'    => admin_url('admin.php?page=fluent-toolkit#mcp'),
            'can_auto_install'     => (bool) apply_filters('fluent_toolkit/can_auto_install', false),
            'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            'plugins_url'          => admin_url('plugins.php'),
            'endpoint_url'         => MCPInit::getEndpointUrl(),
            'tools'                => AbilitiesRegistrar::catalogue(),
            'prompts'              => array_keys(AbilitiesRegistrar::getPromptDefinitions()),
            'app_passwords_url'    => admin_url('profile.php#application-passwords-section'),
            'current_user_login'   => $user->exists() ? $user->user_login : '',
        ]);
    }

    public function toggle(Request $request)
    {
        if (!current_user_can('manage_options')) {
            return $this->forbidden();
        }

        foreach (['mcp_enabled' => 'setEnabled', 'allow_writes' => 'setWritesAllowed'] as $field => $setter) {
            if ($request->exists($field)) {
                PermissionGate::$setter($this->toBool($request->get($field)));
            }
        }

        return $this->sendSuccess([
            'mcp_enabled'    => PermissionGate::isEnabled(),
            'writes_allowed' => PermissionGate::writesAllowed(),
            'message'        => __('MCP settings saved.', 'fluent-player'),
        ]);
    }

    public function installAdapter()
    {
        if (!current_user_can('manage_options') || !current_user_can('install_plugins')) {
            return $this->forbidden();
        }

        if (!apply_filters('fluent_toolkit/can_auto_install', false)) {
            return $this->sendError([
                'message'              => __('Automatic install isn\'t available here. Install FluentHub manually, then reload this page.', 'fluent-player'),
                'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            ]);
        }

        do_action('fluent_toolkit/do_auto_install');
        wp_clean_plugins_cache();

        // The Pro installers swallow their own errors, so check the outcome instead of trusting the action.
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!$this->isToolkitInstalled() || !is_plugin_active(self::TOOLKIT_PLUGIN_FILE)) {
            return $this->sendError([
                'message'              => __('FluentHub could not be installed automatically. Download it and install it manually.', 'fluent-player'),
                'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            ]);
        }

        $available = MCPInit::adapterAvailable();

        return $this->sendSuccess([
            'adapter_available' => $available,
            'toolkit_installed' => $this->isToolkitInstalled(),
            'message'           => $available
                ? __('FluentHub installed. The MCP endpoint is ready.', 'fluent-player')
                : __('FluentHub installed. Reload this page to finish connecting.', 'fluent-player'),
        ]);
    }

    public function configSnippets()
    {
        if (!current_user_can('manage_options')) {
            return $this->forbidden();
        }

        $endpoint = MCPInit::getEndpointUrl();
        $isLocalDev = $this->isLocalDev();

        $snippets = [];
        foreach (['claude-code', 'claude-desktop', 'cursor', 'vscode', 'codex', 'generic'] as $client) {
            $snippets[$client] = $this->buildSnippet($client, $endpoint, $isLocalDev);
        }

        return $this->sendSuccess([
            'snippets'     => $snippets,
            'endpoint'     => $endpoint,
            'is_local_dev' => $isLocalDev,
        ]);
    }

    public function activity()
    {
        if (!current_user_can('manage_options')) {
            return $this->forbidden();
        }

        return $this->sendSuccess(['entries' => ActivityLog::entries()]);
    }

    private function forbidden()
    {
        return $this->sendError(['message' => __('Sorry, you do not have permission to manage MCP settings.', 'fluent-player')], 403);
    }

    private function toBool($value)
    {
        return is_string($value) ? in_array(strtolower($value), ['yes', 'true', '1', 'on'], true) : (bool) $value;
    }

    private function isToolkitInstalled()
    {
        return defined('FLUENT_TOOLKIT_VERSION') || $this->isPluginInstalled(self::TOOLKIT_PLUGIN_FILE);
    }

    private function isPluginInstalled($pluginFile)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return isset(get_plugins()[$pluginFile]);
    }

    private function buildSnippet($client, $endpoint, $isLocalDev)
    {
        $basic = '<base64(your-username:application-password)>';
        $user = '<your-username>';
        $pass = '<your-application-password>';
        $name = MCPInit::SERVER_ID;
        $httpServer = ['url' => $endpoint, 'type' => 'http', 'headers' => ['Authorization' => 'Basic ' . $basic]];

        switch ($client) {
            case 'claude-desktop':
                $env = [
                    'WP_API_URL'      => $endpoint,
                    'WP_API_USERNAME' => $user,
                    'WP_API_PASSWORD' => $pass,
                    'OAUTH_ENABLED'   => 'false',
                ];
                $instructions = __('Add this to your Claude Desktop config (Settings → Developer → Edit Config), fill in your username and application password, then restart Claude Desktop.', 'fluent-player');
                if ($isLocalDev) {
                    // Local self-signed certificates only; never for an internet-facing site.
                    $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
                    $instructions .= ' ' . __('This looks like a local site, so TLS verification is turned off for its self-signed certificate. Remove that line for any public site.', 'fluent-player');
                }
                $snippet = wp_json_encode(['mcpServers' => [$name => [
                    'command' => 'npx',
                    'args'    => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                    'env'     => $env,
                ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $format = 'json';
                break;

            case 'cursor':
                $snippet = wp_json_encode(['mcpServers' => [$name => $httpServer]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $instructions = __('Add this to Cursor’s mcp.json.', 'fluent-player');
                $format = 'json';
                break;

            case 'vscode':
                $snippet = wp_json_encode(['servers' => [$name => $httpServer]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $instructions = __('Add this to .vscode/mcp.json in your workspace (or your user MCP config).', 'fluent-player');
                $format = 'json';
                break;

            case 'codex':
                $snippet = "Settings → Connect to a custom MCP\n\n"
                    . "Name:       {$name}\n"
                    . "Transport:  Streamable HTTP\n"
                    . "URL:        {$endpoint}\n\n"
                    . "Header:\n  Key:    Authorization\n  Value:  Basic {$basic}";
                $instructions = __('In Codex, add a custom MCP server with Streamable HTTP transport and this Authorization header.', 'fluent-player');
                $format = 'text';
                break;

            case 'generic':
                $snippet = "URL:   {$endpoint}\n"
                    . "Auth:  Authorization: Basic {$basic}\n\n"
                    . "# Quick test:\n"
                    . "curl -s -u '{$user}:{$pass}' \\\n"
                    . "  -X POST {$endpoint} \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . "  -H 'Accept: application/json, text/event-stream' \\\n"
                    . '  -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}\'';
                $instructions = __('Any MCP client that speaks Streamable HTTP can connect with this URL and a Basic auth header.', 'fluent-player');
                $format = 'shell';
                break;

            case 'claude-code':
            default:
                $snippet = "claude mcp add \\\n"
                    . "  --transport http \\\n"
                    . "  {$name} {$endpoint} \\\n"
                    . "  --header \"Authorization: Basic {$basic}\"";
                $instructions = __('Run this in the terminal where Claude Code is installed.', 'fluent-player');
                $format = 'shell';
                break;
        }

        return [
            'client'       => $client,
            'snippet'      => $snippet,
            'instructions' => $instructions,
            'format'       => $format,
        ];
    }

    private function isLocalDev()
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        foreach (['.test', '.local', '.localhost', '.lab'] as $tld) {
            if ($host && substr($host, -strlen($tld)) === $tld) {
                return true;
            }
        }

        return false;
    }
}
