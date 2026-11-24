<?php

namespace FluentPlayer\App\Integrations\FluentCart;

if (!defined('ABSPATH')) exit;

/**
 * FluentCart commerce layer, plugged into the core layer system through hooks.
 *
 * Sanitizing and the coupon permission gate run even while FluentCart is
 * inactive: saved layers keep their fields, and a key with no sanitize rule
 * would be stored raw. Rendering and the editor picker check isActive().
 */
class FluentCartModule
{
    public function register()
    {
        // Security hooks run last so an earlier filter cannot undo them.
        add_filter('fluent_player/layer_sanitize_rules', [$this, 'addSanitizeRules'], PHP_INT_MAX);
        add_filter('fluent_player/before_save_media_settings', [FluentCartService::class, 'filterUnauthorizedCouponBindings'], PHP_INT_MAX, 2);
        add_filter('fluent_player/fluent_community_blocked_layer_types', [$this, 'blockInFluentCommunity'], PHP_INT_MAX);
        add_filter('fluent_player/render_layer_fluentcart', [$this, 'renderLayer'], 10, 2);
        add_filter('fluent_player/media_block_vars', [$this, 'addBlockVars']);
        add_action('fluent_player/layer_routes', [$this, 'registerRoutes']);

        return $this;
    }

    public function addSanitizeRules($rules)
    {
        return array_merge((array) $rules, [
            'layers.*.fc_content'            => 'sanitizeTextField',
            'layers.*.keep_playing'          => 'rest_sanitize_boolean',
            'layers.*.product_id'            => 'intval',
            'layers.*.variation_id'          => 'intval',
            'layers.*.product_ids.*'         => 'intval',
            'layers.*.per_page'              => 'intval',
            'layers.*.grid_layout'           => 'sanitizeTextField',
            'layers.*.coupon_id'             => 'intval',
            'layers.*.copy_button_text'      => 'sanitizeTextField',
            'layers.*.coupon_headline'       => 'ksesPost',
            'layers.*.copy_button_style.bg_color'      => 'sanitizeTextField',
            'layers.*.copy_button_style.text_color'    => 'sanitizeTextField',
            'layers.*.copy_button_style.border_color'  => 'sanitizeTextField',
            'layers.*.copy_button_style.border_width'  => 'intval',
            'layers.*.copy_button_style.padding'       => 'intval',
            'layers.*.copy_button_style.border_radius' => 'intval',
            'layers.*.show_buy_now'          => 'rest_sanitize_boolean',
            'layers.*.show_add_to_cart'      => 'rest_sanitize_boolean',
            'layers.*.buy_now_text'          => 'sanitizeTextField',
            'layers.*.add_to_cart_text'      => 'sanitizeTextField',
            'layers.*.container_padding'     => 'intval',
            'layers.*.container_radius'      => 'intval',
            'layers.*.button_bg_color'       => 'sanitizeTextField',
            'layers.*.button_text_color'     => 'sanitizeTextField',
            'layers.*.buy_now_style.bg_color'         => 'sanitizeTextField',
            'layers.*.buy_now_style.text_color'       => 'sanitizeTextField',
            'layers.*.buy_now_style.border_color'     => 'sanitizeTextField',
            'layers.*.buy_now_style.border_width'     => 'intval',
            'layers.*.buy_now_style.padding'          => 'intval',
            'layers.*.buy_now_style.border_radius'    => 'intval',
            'layers.*.add_to_cart_style.bg_color'     => 'sanitizeTextField',
            'layers.*.add_to_cart_style.text_color'   => 'sanitizeTextField',
            'layers.*.add_to_cart_style.border_color' => 'sanitizeTextField',
            'layers.*.add_to_cart_style.border_width' => 'intval',
            'layers.*.add_to_cart_style.padding'      => 'intval',
            'layers.*.add_to_cart_style.border_radius' => 'intval',
        ]);
    }

    public function renderLayer($html, $layer)
    {
        return FluentCartService::renderLayer($layer);
    }

    /**
     * FluentCommunity's REST render path never runs FluentCart's asset enqueue,
     * so the buttons would render dead; its settings can also be client-supplied.
     */
    public function blockInFluentCommunity($types)
    {
        return array_merge((array) $types, ['fluentcart']);
    }

    public function addBlockVars($vars)
    {
        $vars['fluentCart'] = [
            'enable'     => FluentCartService::isActive(),
            'installUrl' => admin_url('plugin-install.php?tab=search&type=term&s=fluentcart'),
        ];

        return $vars;
    }

    public function registerRoutes($router)
    {
        $controller = FluentCartController::class;

        $router->get('/fluentcart/products', $controller . '@getFluentCartProducts');
        $router->get('/fluentcart/coupons', $controller . '@getFluentCartCoupons');
        $router->get('/fluentcart/preview', $controller . '@getFluentCartPreview');
    }
}
