<?php

namespace FluentPlayer\App\Blocks;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\IntegrationService;
use FluentPlayer\App\Services\MediaRenderer;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\App\Services\SettingsService;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;
use FluentPlayer\App\Utils\Enqueuer\Vite;
use FluentPlayer\App\Helpers\Helper;

class MediaBlock
{
    private static $isReactSupportAdded = false;

    /**
     * Register the block
     */
    public function register()
    {
        // Allow other plugins to prevent registration
        if (apply_filters('fluent_player/should_register_media_block', true) === false) {
            return;
        }

        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        add_action('enqueue_block_assets', [$this, 'enqueueBlockAssets']);

        // Register the block
        if (function_exists('register_block_type')) {
            register_block_type('fluent-player/media', [
                'api_version'     => 3,
                'editor_script'   => 'fluent-player-block-editor',
                'editor_style'    => 'fluent-player-block-editor-style',
                'render_callback' => [$this, 'render'],
                'attributes'      => [
                    'mediaId'      => [
                        'type' => 'number'
                    ],
                    'preview'      => [
                        'type'    => 'boolean',
                        'default' => true
                    ],
                    'autoplay'     => [
                        'type'    => 'boolean',
                        'default' => false
                    ],
                    'showControls' => [
                        'type'    => 'boolean',
                        'default' => true
                    ],
                    'brandColor'   => [
                        'type'    => 'string',
                        'default' => '#007bff'
                    ],
                    'cssClass'     => [
                        'type'    => 'string',
                        'default' => ''
                    ],
                    'align'        => [
                        'type'    => 'string',
                        'default' => ''
                    ],
                    'className'    => [
                        'type'    => 'string',
                        'default' => ''
                    ],
                    'timedContentStyle' => [
                        'type'    => 'object',
                        'default' => [
                            'enabled' => true,
                            'padding' => [
                                'top'    => '20',
                                'right'  => '20',
                                'bottom' => '20',
                                'left'   => '20',
                                'unit'   => 'px',
                                'linked' => true,
                            ]
                        ]
                    ]
                ]
            ]);

        }
    }


    /**
     * Register block editor assets
     */
    public function enqueueBlockEditorAssets()
    {
        // Register the block assets first
        $this->registerBlockAssets();
        $this->addReactSupport();

        // Load wp.editor (TinyMCE) + media library for CTA layer WP Editor
        wp_enqueue_editor();
        wp_enqueue_media();

        // Prepare block editor data
        $this->prepareBlockEditorData();
    }

    public function enqueueBlockAssets()
    {
        // Only load block editor assets in the admin block editor, not on frontend
        if (!is_admin()) {
            return;
        }

        $current_screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($current_screen && !$current_screen->is_block_editor()) {
            return;
        }

        Enqueue::style(
            'fluent-player-block-style',
            'scss/fluent-player-block.scss',
            [],
            FLUENT_PLAYER_VERSION
        );

        // FluentCommunity uses a separate editor iframe bootstrap and injects a small
        // vidstack loader there via the `fluent_community/block_editor_settings` hook.
        // Loading the full block editor bundle in that iframe breaks production because
        // the iframe does not expose the Gutenberg `wp.*` globals the bundle expects.
        // Keep the standard iframe script for core WP block editor only.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context check
        if (isset($_GET['fluent_community_block_editor'])) {
            return;
        }

        // This enqueue is required for apiVersion 3 in the normal WP block editor —
        // enqueue_block_assets loads scripts inside the editor iframe, which the
        // YouTube bridge needs to function correctly.
        Enqueue::script(
            'fluent-player-block-editor',
            'blocks/media/fluent-player-block.jsx',
            [
                'underscore',
                'wp-blocks',
                'wp-element',
                'wp-i18n',
                'wp-components',
                'wp-block-editor',
                'wp-api-fetch',
                'wp-rich-text'
            ],
            FLUENT_PLAYER_VERSION,
            true
        );
    }

    /**
     * Prepare block editor data
     */
    public function prepareBlockEditorData()
    {
        // Use MediaService to get media data
        $mediaService = new MediaService();
        $paginator = $mediaService->paginate(['per_page' => 100, 'status' => 'publish']);
        $mediaItems = $paginator->getCollection();

        // Get all frontend settings at once
        $defaultSettings = SettingsService::getMediaDefaultSettings();

        $mediaBlockVars = [
            'mediaItems'          => $this->getMediaItems($mediaItems),
            'rest'                => Helper::getRestInfo(),
            'pluginUrl'           => FLUENT_PLAYER_URL,
            'defaultSettings'     => $defaultSettings,
            'hasPro'              => Helper::hasPro(),
            'subtitleApi'         => $this->getSubtitleApiFlags(),
            'languages'           => Helper::getLanguages(),
            'languageFlags'       => Helper::getTopLanguagesWithFlags(),
            'svgIcons'            => Helper::getSvgIcons(),
            'has_fluentcrm'       => defined('FLUENTCRM'),
            'fluentcrm_install_url' => admin_url('plugin-install.php?tab=search&type=term&s=fluentcrm'),
            'adminUrls'           => [
                'media'         => admin_url('admin.php?page=fluent-player#/'),
                'analyticsBase' => admin_url('admin.php?page=fluent-player#/analytics/video/'),
                'editBase'      => admin_url('post.php?action=edit&post='),
            ],
        ];

        // Allow developers to modify the media block variables
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $mediaBlockVars = apply_filters('fluent_player/media_block_vars', $mediaBlockVars, $defaultSettings);

        wp_localize_script('fluent-player-block-editor', 'fluentPlayerBlockVars', $mediaBlockVars);
    }

    private function getSubtitleApiFlags()
    {
        return [
            'upload'            => false,
            'delete'            => false,
            'youtubeImport'     => false,
            'youtubeStoryboard' => false,
            'maxTrackSelection' => 10,
        ];
    }

    /**
     * Renders the `fluent-player/media` block on the server.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Inner blocks content (timed content sections).
     *
     * @return string Rendered block output.
     */
    public function render($attributes, $content = '')
    {
        // Check if media ID is set
        if (empty($attributes['mediaId'])) {
            return '<div class="fluent-player-empty">' . esc_html__('Please select a media to display.',
                    'fluent-player') . '</div>';
        }

        // Get the media ID
        $media_id = absint($attributes['mediaId']);

        $media = Media::findVisible($media_id);
        if (!$media) {
            return Media::getAccessDeniedCurtain($media_id);
        }

        // Allow other plugins to modify the attributes
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $attributes = apply_filters('fluent_player/block_media_attributes', $attributes, $media_id);

        // Allow other plugins to override the rendering
        $custom_output = apply_filters('fluent_player/pre_render_block_media', '', $attributes, $media_id);
        if (!empty($custom_output)) {
            return $custom_output;
        }

        // Render the player directly via the shared renderer
        $extraClasses = !empty($attributes['className']) ? $attributes['className'] : '';
        $player_html = MediaRenderer::render($media_id, $extraClasses);

        if (empty($player_html)) {
            return '';
        }

        $output = Media::getStatusNotice($media) . $player_html;

        // Skip media_block_inner for locked media so timed content can't leak past the form.
        if (!post_password_required($media_id)) {
            $content = trim($content);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed
            $output = apply_filters('fluent_player/media_block_inner', $output, $attributes, $media_id, $content);
        }

        // Wrap with alignment class if set
        if (!empty($attributes['align'])) {
            $output = '<div class="align' . esc_attr($attributes['align']) . '">' . $output . '</div>';
        }

        // Allow other plugins to modify the final output
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        return apply_filters('fluent_player/block_media_output', $output, $attributes, $media_id);
    }

    protected function registerBlockAssets()
    {
        // Register block editor script directly
        Enqueue::script(
            'fluent-player-block-editor',
            'blocks/media/fluent-player-block.jsx',
            [
                'underscore',
                'wp-blocks',
                'wp-element',
                'wp-i18n',
                'wp-components',
                'wp-block-editor',
                'wp-api-fetch',
                'wp-rich-text'
            ],
            \FLUENT_PLAYER_VERSION,
            true
        );

        Enqueue::style(
            'fluent-player-block-editor-style',
            'scss/fluent-player-block.scss',
            [],
            FLUENT_PLAYER_VERSION
        );

    }

    private function addReactSupport()
    {
        if (!static::$isReactSupportAdded && Vite::isOnDevMode()) {
            Enqueue::script(
                'react-support',
                'blocks/reactSupport.jsx',
                ['wp-blocks', 'wp-components']
            );
            static::$isReactSupportAdded = true;
        }
    }

    private function getMediaItems($mediaItems)
    {
        // Format media items for the block editor
        $formattedItems = [];
        foreach ($mediaItems as $item) {
            $formattedItems[$item->ID] = [
                'value'    => $item->ID,
                'label'    => ($item->post_title ?: 'Media') . ' (#' . $item->ID . ')',
                'title'    => $item->post_title ?: 'Media',
                'settings' => $item->settings,
            ];
        }
        return $formattedItems;
    }
}
