<?php

namespace FluentPlayer\App\Hooks\Handlers;
if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;
use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Services\Translations\TransStrings;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;
use FluentPlayer\Framework\Support\Arr;

class AdminMenuHandler
{
    /**
     * $app Application instance
     * @var WPFluent\Foundation\Application
     */
    protected $app;

    /**
     * $app Config instance
     * @var WPFluent\Foundation\Config
     */
    protected $config;

    /**
     * $position Menu Position
     * @var int|float
     */
    protected $position = 11;


    /**
     * Construct the instance
     */
    public function __construct()
    {
        $this->app = App::make();
        $this->config = $this->app->config;
    }

    /**
     * Add Custom Menu
     *
     */
    public function handle()
    {
        $slug = $this->config->get('app.slug');


        $title = __('FluentPlayer', 'fluent-player');

        if(defined('FLUENT_PLAYER_PRO_VERSION')) {
            $title = __('FluentPlayer Pro', 'fluent-player');
        }

        // Add main menu page
        add_menu_page(
            $title,
            $title,
            'manage_options',
            $slug,
            [$this, 'render'],
            $this->getMenuIcon(),
            $this->position
        );

        // Add Media as first submenu to match the parent menu
        add_submenu_page(
            $slug,
            __('Media', 'fluent-player'),
            __('Media', 'fluent-player'),
            'manage_options',
            $slug,
            [$this, 'render']
        );

        // Add other submenu items based on the menu items
        $menuItems = $this->getMenuItems(admin_url('admin.php?page=' . $slug . '#/'));

        // Add submenu items for each menu item except 'medias'. Analytics is
        // intentionally omitted from the WP sidebar — it lives in the app's
        // top nav only.
        foreach ($menuItems as $item) {
            if (in_array($item['key'], ['medias', 'analytics'], true)) {
                continue;
            }

            add_submenu_page(
                $slug,
                $item['label'],
                $item['label'],
                'manage_options',
                'admin.php?page=' . $slug . '#/' . $item['key'],
                null
            );
        }

        add_action('current_screen', [$this, 'highlightMenuForMediaEditor']);
        add_filter('admin_body_class', [$this, 'addAdminBodyClasses']);
        add_action('admin_head', [$this, 'addLoadingStyles']);
        add_filter('admin_footer_text', [$this, 'adminFooterText'], 10, 1);
        add_filter('update_footer', [$this, 'updateFooter'], 10, 1);
    }

    /**
     * Prepend "All Media" and "Settings" links to the plugin's row on the Plugins screen.
     *
     * @param array $links
     * @return array
     */
    public function addPluginActionLinks($links)
    {
        if (!current_user_can('manage_options')) {
            return $links;
        }

        $slug = $this->config->get('app.slug');

        $actionLinks = [
            'medias'   => sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . $slug . '#/')),
                esc_html__('All Media', 'fluent-player')
            ),
            'settings' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . $slug . '#/settings')),
                esc_html__('Settings', 'fluent-player')
            ),
        ];

        return array_merge($actionLinks, $links);
    }

    /**
     * Prints critical inline CSS to the admin <head> to prevent FOUC.
     * This is the most reliable method to ensure styles are applied before the body renders.
     */
    public function addLoadingStyles()
    {
        // Only run this on our specific admin page to avoid affecting other pages.
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'fluent-player') === false) {
            return;
        }
        $body_class = esc_attr($screen->id);
        ?>
        <style id="fluent-player-admin-page-styles">

            <?php echo 'body.' . esc_attr($body_class) . ' .notice:not(.fluent-player-pro-notice), body.' . esc_attr($body_class) . ' .update-nag { display: none !important; }'; ?>
            <?php echo 'body.' . esc_attr($body_class) . ' .fluent-player-pro-notice { display: block !important; position: relative; z-index: 12; margin: 16px 20px 0 0; }'; ?>
            <?php echo 'body.' . esc_attr($body_class) . ' .fluent-player-pro-notice + .fluent-player-app { margin-top: 16px; }'; ?>

            <?php echo 'body.' . esc_attr($body_class) . ' #wpadminbar { z-index: 1017; }'; ?>
            /* Reserve the scrollbar gutter so the empty boot state and the loaded state
               keep the same content width (prevents the nav shifting on load). */
            html { scrollbar-gutter: stable; }

            /* Critical header styles — prevent FOUC of the nav before the app stylesheet loads.
               Literal values (not --fp-* vars, which live in the not-yet-loaded app CSS); must match elements/_nav.scss.
               margin-left lives on .fplayer-app (same element admin.scss targets) to avoid compounding. */
            .fluent-player-app { position: relative; width: 100%; min-height: calc(100vh - 32px); background: #F3F5FA; }
            .fluent-player-app .fplayer-app { margin-left: -20px; background: #F3F5FA; }
            .fluent-player-app .fplayer-main-menu-items { position: sticky; top: 0; z-index: 2000; background: #FFFFFF; }
            .fluent-player-app .fplayer-navbar { box-sizing: border-box; display: flex; align-items: center; justify-content: space-between; height: 64px; padding: 0 30px; background: #FFFFFF; box-shadow: 0 0 20px 0 rgba(28, 39, 50, 0.08); }
            .fluent-player-app .fplayer-navbar-left { display: flex; align-items: center; }
            .fluent-player-app .fplayer-navbar-center { display: flex; flex: 1; align-items: center; justify-content: center; }
            .fluent-player-app .fplayer-navbar-right { display: flex; align-items: center; gap: 8px; }
            .fluent-player-app .fplayer-logo { display: block; height: 32px; width: auto; }
            .fluent-player-app ul.fplayer-menu { display: flex; gap: 4px; align-items: center; margin: 0; padding: 0; list-style: none; }
            .fluent-player-app ul.fplayer-menu li { margin: 0; padding: 0; list-style: none; }
            .fluent-player-app .fplayer-menu-primary { display: block; padding: 10px 12px; border: 1px solid transparent; border-radius: 8px; font-weight: 500; font-size: 14px; line-height: 1; letter-spacing: 0.025em; color: #2F3448; text-decoration: none; }
            .fluent-player-app .fplayer-menu-item.active-item .fplayer-menu-primary { background: #F0F0F1; color: #253241; }
            .fluent-player-app .fplayer-navbar-settings-link { display: inline-flex; align-items: center; justify-content: center; height: 36px; padding: 8px; border-radius: 8px; }
            .fluent-player-app .fplayer-docs-link,
            .fluent-player-app .fplayer-pro-badge { box-sizing: border-box; display: inline-flex; align-items: center; gap: 4px; height: 36px; padding: 8px 12px; border-radius: 8px; text-decoration: none; }
            .fluent-player-app .fplayer-docs-link { background: #F2F5F8; color: #525866; }
            .fluent-player-app .fplayer-pro-badge { background: <?php echo esc_attr(Helper::DEFAULT_BRAND_COLOR); ?>; color: #FFFFFF; }
            .fluent-player-app .fplayer-docs-link span,
            .fluent-player-app .fplayer-pro-badge span { font-size: 14px; font-weight: 500; }
            .fluent-player-app .fplayer-handheld { display: none; }
            .fluent-player-app .fplayer-body { padding: 24px 32px; background: #F3F5FA; }

            @media screen and (max-width: 480px) {
                .fluent-player-app .fplayer-navbar { padding: 0 16px; }
                .fluent-player-app ul.fplayer-menu { display: none; }
                .fluent-player-app .fplayer-navbar-center { justify-content: flex-end; }
                .fluent-player-app .fplayer-handheld { display: flex; }
                .fluent-player-app .fplayer-body { padding: 0 16px; }
            }
        </style>
        <?php
    }

    /**
     * Replace admin footer left text on Fluent Player admin pages.
     *
     * @param string $text Default footer text.
     * @return string
     */
    public function adminFooterText($text)
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'fluent-player') === false) {
            return $text;
        }
        return sprintf(
            /* translators: 1: 5-star rating link to the WP.org reviews page, 2: heart symbol, 3: link to WordPress.org. */
            esc_html__('If you like the plugin please rate FluentPlayer %1$s on %3$s to help us spread the word %2$s from the FluentPlayer team.', 'fluent-player'),
            '<a href="https://wordpress.org/support/plugin/fluent-player/reviews/#new-post" target="_blank" rel="noopener noreferrer">★★★★★</a>',
            '♥',
            '<a href="https://wordpress.org/support/plugin/fluent-player/reviews/#new-post" target="_blank" rel="noopener noreferrer">WordPress.org</a>'
        );
    }

    /**
     * Replace admin footer right text (version) on Fluent Player admin pages.
     *
     * @param string $text Default footer text.
     * @return string
     */
    public function updateFooter($text)
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'fluent-player') === false) {
            return $text;
        }
        $version = defined('FLUENT_PLAYER_VERSION') ? FLUENT_PLAYER_VERSION : '1.0.0';
        $footer = 'Version ' . esc_html($version);
        if (Helper::hasPro() && defined('FLUENT_PLAYER_PRO_VERSION')) {
            $footer .= ' & Pro ' . esc_html(constant('FLUENT_PLAYER_PRO_VERSION'));
        }
        return $footer;
    }

    /**
     * Render the menu page
     *
     * @return null
     */

    public function render()
    {
        $this->enqueueAssets(
            $slug = $this->config->get('app.slug')
        );

        $baseUrl = $this->app->applyFilters(
            'fluent_player/base_url',
            admin_url('admin.php?page=' . $slug . '#/')
        );

        $this->app->view->render('admin.menu', [
            'name'      => $this->config->get('app.name'),
            'slug'      => $slug,
            'baseUrl'   => $baseUrl,
            'menuItems' => $this->getMenuItems($baseUrl),
            'logo'      => Enqueue::getStaticFilePath('images/logo.svg'),
        ]);
    }

    /**
     * Get and map menu items for main nav.
     *
     * @param string $baseUrl
     * @return array
     */
    protected function getMenuItems($baseUrl)
    {
        $menuItems = [
            [
                'key'       => 'medias',
                'label'     => __('Media', 'fluent-player'),
                'permalink' => $baseUrl
            ],
            [
                'key'       => 'playlists',
                'label'     => __('Playlists', 'fluent-player'),
                'permalink' => $baseUrl . 'playlists'
            ],
        ];


        // Analytics — always visible in menu; the page itself shows the
        // disabled/enabled state (and a pro upgrade when pro is not active).
        $menuItems[] = [
            'key'       => 'analytics',
            'label'     => __('Analytics', 'fluent-player'),
            'permalink' => $baseUrl . 'analytics',
        ];

        $menuItems[] = [
            'key'       => 'settings',
            'label'     => __('Settings', 'fluent-player'),
            'permalink' => $baseUrl . 'settings'
        ];

        return $this->app->applyCustomFilters(
            'admin_menu_items', $menuItems
        );
    }

    /**
     * Enqueue all the scripts and styles
     *
     * @param string $slug
     * @return null
     */
    public function enqueueAssets($slug)
    {
        Enqueue::style($slug . '_admin_app', 'scss/admin/admin.scss');

        $this->app->doAction($slug . '_loading_app');

        wp_enqueue_media();
        wp_enqueue_editor();

        Enqueue::script(
            $slug . '_admin_app',
            'admin/app.js',
            ['jquery'],
            '1.0',
            true
        );

        $this->localizeScript($slug);
    }

    /**
     * Push/Localize the JavaScript variables
     *
     * to the browser using wp_localize_script.
     *
     * @param string $slug
     * @return null
     */
    protected function localizeScript($slug)
    {
        $authUser = get_user_by('ID', get_current_user_id());
        $settings = Helper::getSettings();
        $analytics = Arr::get($settings, 'analytics', []);
        $googleAnalytics = Arr::get($settings, 'google_analytics', []);

        $brandColor = Arr::get($settings, 'branding.brand_color', Helper::DEFAULT_BRAND_COLOR);

        $adminVars = [
            'slug'                  => $slug,
            'user_locale'           => get_locale(),
            'brand_logo'            => $this->getMenuIcon(),
            'brand_color'           => $brandColor,
            'nonce'                 => wp_create_nonce($slug),
            'admin_url'             => admin_url(),
            'home_url'              => home_url(),
            'media_view_url'        => home_url(FluentPlayerMediaCPT::$url_slug),
            'asset_url'             => $this->app['url.assets'],
            'rest'                  => Helper::getRestInfo(),
            'me'                    => [
                'id'        => $authUser->ID ?? null,
                'email'     => $authUser->user_email ?? null,
                'full_name' => $authUser->display_name ?? null
            ],
            'analytics'             => $analytics,
            'google_analytics'      => $googleAnalytics,
            'has_pro'               => Helper::hasPro(),
            'admin_notices'         => array_values(array_map(static function ($notice) {
                if (is_array($notice) && isset($notice['html'])) {
                    $notice['html'] = wp_kses_post($notice['html']);
                }
                return $notice;
            }, (array) apply_filters('fluent_player/admin_notices', []))),
            'date_format'           => get_option('date_format', 'F j, Y'),
            'time_format'           => get_option('time_format', 'g:i a'),
            'trans'                 => TransStrings::getStrings(),
            'has_fluentcrm'         => defined('FLUENTCRM'),
            'fluentcrm_install_url' => admin_url('plugin-install.php?tab=search&type=term&s=fluentcrm'),
        ];
        $adminVars = $this->app->applyFilters('fluent_player/admin_vars', $adminVars);
        wp_localize_script($slug . '_admin_app', 'fluentFrameworkAdmin', $adminVars);
    }


    /**
     * Get the default icon for custom menu
     * added by the add_menu in the WP menubar.
     *
     * @return string
     */
    protected function getMenuIcon()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><defs><style>.cls-1{fill:#fff;fill-rule:evenodd;}</style></defs><path class="cls-1" d="M25.6,0H230.4C244.14,0,256,11.46,256,25.6V230.4C256,244.54,244.14,256,230.4,256H25.6C11.46,256,0,244.54,0,230.4V25.6C0,11.46,11.46,0,25.6,0ZM153.832,90.5302L95.3656,56.7746C84.5055,50.5045,70.9304,58.3421,70.9304,70.8822V110.178C70.9304,122.718,84.5055,130.556,95.3656,124.286L153.832,90.5302ZM181.623,149.425C198.116,139.903,198.116,116.097,181.623,106.576C179.905,105.584,177.787,105.584,176.069,106.576L80.9016,161.521C74.7314,165.084,70.9304,171.667,70.9304,178.792C70.9304,194.144,87.5492,203.739,100.844,196.063L181.623,149.425Z"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Highlight Fluent Player menu when editing media in block editor
     *
     * @param \WP_Screen $current_screen
     * @return void
     */
    public function highlightMenuForMediaEditor($current_screen)
    {
        // Check if we're editing fluent_player_media post type
        if ($current_screen->post_type === 'fluent_player_media' &&
            in_array($current_screen->base, ['post', 'post-new'])) {

            global $parent_file, $submenu_file;
            $slug = $this->config->get('app.slug');

            // Set the parent menu as active
            $parent_file = $slug;
            $submenu_file = $slug;
        }
    }

    /**
     * Add admin body classes for Fluent Player media editor
     *
     * @param string $classes
     * @return string
     */
    public function addAdminBodyClasses($classes)
    {
        $screen = get_current_screen();

        if ($screen && $screen->post_type === 'fluent_player_media') {
            $classes .= ' fluent-player-media-editor';

            if ($screen->base === 'post') {
                $classes .= ' fluent-player-edit-media';
            } elseif ($screen->base === 'post-new') {
                $classes .= ' fluent-player-new-media';
            }
        }

        return $classes;
    }
}
