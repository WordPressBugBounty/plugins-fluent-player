<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;

class FluentPlayerMediaCPT
{
    /**
     * The custom post type slug
     * @var string
     */
    protected static $post_type = 'fluent_player_media';

    /**
     * The URL slug (different from post type)
     * @var string
     */
    public static $url_slug = 'fluent-player-media';

    /**
     * Register the post type and all related hooks
     */
    public function registerPostType()
    {
        $slug = self::$post_type;

        $labels = [
            'name'               => __('FluentPlayer Media', 'fluent-player'),
            'singular_name'      => __('Media', 'fluent-player'),
            'menu_name'          => __('FluentPlayer', 'fluent-player'),
            'name_admin_bar'     => __('FluentPlayer Media', 'fluent-player'),
            'add_new'            => __('Add New', 'fluent-player'),
            'add_new_item'       => __('Add New Media', 'fluent-player'),
            'new_item'           => __('New Media', 'fluent-player'),
            'edit_item'          => __('Edit Media', 'fluent-player'),
            'view_item'          => __('View Media', 'fluent-player'),
            'all_items'          => __('All Media', 'fluent-player'),
            'search_items'       => __('Search Media', 'fluent-player'),
            'parent_item_colon'  => __('Parent Media:', 'fluent-player'),
            'not_found'          => __('No media found.', 'fluent-player'),
            'not_found_in_trash' => __('No media found in Trash.', 'fluent-player')
        ];

        register_post_type($slug, [
            'labels'                => $labels,
            'public'                => true,
            'publicly_queryable'    => true,
            'show_in_rest'          => true,
            'show_ui'               => true,
            'show_in_menu'          => false,
            'show_in_nav_menus'     => false,
            'show_in_admin_bar'     => true,
            'supports'              => ['title', 'editor', 'custom-fields'],
            'has_archive'           => false,
            'menu_icon'             => 'dashicons-format-video',
            'description'           => __('Custom post type for FluentPlayer media items.', 'fluent-player'),
            'template'              => [
                ['fluent-player/media']
            ],
            'template_lock'         => 'all',
            'rewrite'               => [
                'slug' => self::$url_slug,
                'with_front' => false
            ],
            'menu_position'         => 20,
            'capability_type'       => 'post',
            'hierarchical'          => false,
        ]);

        do_action('fluent_player/register_media_taxonomies');

        add_filter('allowed_block_types_all', [$this, 'restrictAllowedBlocks'], 10, 2);

        add_action('enqueue_block_editor_assets', [$this, 'suppressTemplateValidationNotice']);

        add_filter('admin_title', [$this, 'modifyAdminTitle'], 10, 2);

        add_action('use_block_editor_for_post', [$this, 'forceGutenberg'], 999, 2);

        add_action('admin_enqueue_scripts', [$this, 'enqueueToolbarScript']);

        $dedicatedPage = new DedicatedPlayerPage();
        add_action('template_redirect', [$dedicatedPage, 'handleTemplateRedirect']);
        add_action('wp_enqueue_scripts', [$dedicatedPage, 'enqueueConditionalStyles']);

        // Add the filter to modify permalinks to use post ID instead of title
        add_filter('post_type_link', [$this, 'customizePermalink'], 10, 2);
    }

    /**
     * Add custom rewrite rules for fluent_player_media post - slug-based permalinks
     */
    public static function addRewriteRules()
    {
        // Fallback rule for untitled posts: /fluent-player-media/media-{ID}/
        add_rewrite_rule(
            self::$url_slug . '/media-([0-9]+)/?$',
            'index.php?post_type=' . self::$post_type . '&p=$matches[1]',
            'top'
        );
        // Primary rule for slug-based URLs: /fluent-player-media/{slug}/
        add_rewrite_rule(
            self::$url_slug . '/([^/]+)/?$',
            'index.php?post_type=' . self::$post_type . '&name=$matches[1]',
            'top'
        );
        update_option('fluent_player_rewrite_rules_added', true);
    }

    /**
     * Customize the permalink structure for fluent_player_media post type
     */
    public function customizePermalink($post_link, $post)
    {
        if (isset($post->post_type) && $post->post_type === self::$post_type) {
            // Use post_name (slug) if it's a valid non-numeric slug
            // Fallback to 'media-{ID}' for untitled posts (where post_name is empty or just the ID)
            $hasValidSlug = !empty($post->post_name) && !is_numeric($post->post_name);
            $slug = $hasValidSlug ? $post->post_name : 'media-' . $post->ID;
            return home_url(self::$url_slug . '/' . $slug . '/');
        }
        return $post_link;
    }

    /**
     * Force Gutenberg editor for our custom post type
     */
    public function forceGutenberg($use, $post)
    {
        if (self::$post_type === $post->post_type) {
            return true;
        }
        return $use;
    }

    /**
     * Restrict allowed blocks to only the FluentPlayer block
     */
    public function restrictAllowedBlocks($allowed_blocks, $context)
    {
        // Check if we're in the block editor and editing a fluent_player_media post
        if (!empty($context->post) && $context->post->post_type === self::$post_type) {
            $blocks = [
                'fluent-player/media',
            ];

            if (Helper::hasPro()) {
                $blocks = array_merge($blocks, [
                    'fluent-player/timed-content',
                    // Core blocks allowed inside timed-content sections
                    'core/paragraph',
                    'core/heading',
                    'core/image',
                    'core/list',
                    'core/list-item',
                    'core/quote',
                    'core/buttons',
                    'core/button',
                    'core/columns',
                    'core/column',
                    'core/group',
                    'core/separator',
                    'core/spacer',
                    'core/html',
                    'core/shortcode',
                    // Third-party blocks for timed content integrations
                    'fluentfom/guten-block',
                    'fluent-booking/calendar',
                    'fluent-booking/team-management',
                    'fluent-booking/calendar-management',
                    'fluent-booking/booking-management',
                ]);
            }

            return $blocks;
        }

        return $allowed_blocks;
    }

    /**
     * Suppress the spurious "content doesn't match the template" notice — but only
     * for the known false positive caused by the fluent-player/media block.
     */
    public function suppressTemplateValidationNotice()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (
            !$screen || $screen->post_type !== self::$post_type
            || (method_exists($screen, 'is_block_editor') && !$screen->is_block_editor())
        ) {
            return;
        }

        $js = <<<'JS'
( function ( wp ) {
    if ( ! wp || ! wp.data || ! wp.data.subscribe ) { return; }
    var MEDIA_BLOCK = 'fluent-player/media';
    var keepTemplateValid = function () {
        var editor = wp.data.select( 'core/editor' );
        var blockEditor = wp.data.select( 'core/block-editor' );
        if ( ! editor || typeof editor.isValidTemplate !== 'function'
            || ! blockEditor || editor.isValidTemplate() !== false ) {
            return;
        }
        // Only the fluent-player/media block may legitimately trip the validator
        // (its timed-content inner blocks). A document that is exactly one media
        // block is the benign false positive; anything else is a real mismatch.
        var blocks = blockEditor.getBlocks();
        var onlyMediaBlock = blocks.length === 1 && blocks[0] && blocks[0].name === MEDIA_BLOCK;
        if ( onlyMediaBlock ) {
            wp.data.dispatch( 'core/editor' ).setTemplateValidity( true );
        }
    };
    wp.data.subscribe( keepTemplateValid );
    keepTemplateValid();
} )( window.wp );
JS;

        wp_add_inline_script('wp-data', $js);
    }

    /**
     * Modify the admin title for our custom post type
     */
    public function modifyAdminTitle($admin_title, $title)
    {
        global $post_type, $pagenow;

        // Ensure $pagenow is set
        if (!isset($pagenow)) {
            return $admin_title;
        }

        // Handle editing existing post
        if ($pagenow === 'post.php' && isset($post_type) && $post_type === self::$post_type) {
            return __('FluentPlayer Media Editor', 'fluent-player') . ' &lsaquo; ' . get_bloginfo('name');
        }

        // Handle creating new post - check $_GET safely
        if ($pagenow === 'post-new.php') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only reading post_type for display purposes, sanitized on next line
            $postTypeRaw = isset($_GET['post_type']) ? \wp_unslash($_GET['post_type']) : '';
            $postType = \sanitize_text_field($postTypeRaw);
            if ($postType === self::$post_type) {
                return __('Add New FluentPlayer Media', 'fluent-player') . ' &lsaquo; ' . get_bloginfo('name');
            }
        }

        return $admin_title;
    }

    /**
     * Enqueue toolbar script for block editor
     */
    public function enqueueToolbarScript()
    {
        global $post_type, $pagenow;

        if (!in_array($pagenow, ['post.php', 'post-new.php']) || $post_type !== self::$post_type) {
            return;
        }

        if (!$this->isBlockEditor()) {
            return;
        }
        Enqueue::script(
            'fluent-player-media-toolbar',
            'blocks/mediaToolbar.jsx',
            ['wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-editor'],
            FLUENT_PLAYER_VERSION,
            true
        );

        // Destination for the editor "Back" button (replaces the close/site-icon).
        // Media returns to the media list; Pro localizes #/playlists for playlists.
        wp_localize_script('fluent-player-media-toolbar', 'fluentPlayerToolbar', $this->getBackButtonVars());
    }

    /**
     * Localize payload for the editor "Back" button on the media CPT — the link
     * returns to the Fluent Player media list. (Pro provides its own payload
     * pointing at #/playlists for the playlist CPT.)
     *
     * @return array{backUrl:string, backLabel:string}
     */
    public function getBackButtonVars()
    {
        return [
            'backUrl'   => admin_url('admin.php?page=fluent-player#/'),
            'backLabel' => __('Back', 'fluent-player'),
        ];
    }

    /**
     * Check if we're in the block editor
     */
    private function isBlockEditor()
    {
        if (function_exists('is_gutenberg_page') && is_gutenberg_page()) {
            return true;
        }

        if (function_exists('use_block_editor_for_post')) {
            global $post;
            if ($post && use_block_editor_for_post($post)) {
                return true;
            }
        }

        // Fallback check for WordPress 5.0+
        return function_exists('register_block_type');
    }
}
