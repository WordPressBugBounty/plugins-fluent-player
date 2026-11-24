<?php

namespace FluentPlayer\App\Modules\MCP\Tools;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Modules\MCP\AbilitiesRegistrar;
use FluentPlayer\App\Modules\MCP\Support\MCPHelper;
use FluentPlayer\App\Modules\MCP\Support\PermissionGate;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\App\Services\PresetService;

class ContextTools
{
    public static function definitions()
    {
        return [
            'fluent-player/get-player-context' => [
                'label'            => __('Get Fluent Player Context', 'fluent-player'),
                'description'      => __('Start here. Returns media counts, presets, supported providers, Pro status, whether writes are allowed, and how to use the other tools.', 'fluent-player'),
                'group'            => __('General', 'fluent-player'),
                'capability'       => Helper::authoringCapability(),
                'annotations'      => ['readonly' => true],
                'input_schema'     => ['type' => 'object'],
                'execute_callback' => [self::class, 'getPlayerContext'],
            ],
        ];
    }

    public static function getPlayerContext($params = [])
    {
        $counts = (array) wp_count_posts(Media::$postType);
        $presets = array_values(array_map(function ($preset) {
            return [
                'slug'  => isset($preset['slug']) ? $preset['slug'] : '',
                'title' => MCPHelper::untrusted(isset($preset['name']) ? $preset['name'] : (isset($preset['title']) ? $preset['title'] : '')),
            ];
        }, (array) PresetService::all()));

        $data = [
            'plugin_version' => defined('FLUENT_PLAYER_VERSION') ? FLUENT_PLAYER_VERSION : '',
            'pro_active'     => Helper::hasPro(),
            'writes_allowed' => PermissionGate::writesAllowed(),
            'media_counts'   => [
                'publish' => isset($counts['publish']) ? (int) $counts['publish'] : 0,
                'draft'   => isset($counts['draft']) ? (int) $counts['draft'] : 0,
                'private' => isset($counts['private']) ? (int) $counts['private'] : 0,
                'trash'   => isset($counts['trash']) ? (int) $counts['trash'] : 0,
            ],
            'presets'        => $presets,
            'providers'      => array_values(MediaService::allowedProviders()),
            'tools'          => array_map([MCPHelper::class, 'mcpToolName'], array_keys(AbilitiesRegistrar::getDefinitions())),
            'guide'          => self::guide(),
        ];

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: published media count, 2: preset count */
                __('%1$d published media, %2$d presets.', 'fluent-player'),
                $data['media_counts']['publish'],
                count($presets)
            ),
            $data,
            ['content_warning' => MCPHelper::CONTENT_WARNING]
        );
    }

    private static function guide()
    {
        return [
            'Write tools need "Allow AI to make changes" in Fluent Player → Settings → AI / MCP.',
            'Every write first runs with dry_run: true and returns a preview plus confirm_token. Show the preview to the user, then repeat the call with that confirm_token.',
            'Media and playlists the AI creates are saved as drafts. Visitors see a login curtain until a human publishes them.',
            'Embed a media item with the shortcode or block markup from fluent-player-get-media.',
        ];
    }
}
