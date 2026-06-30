<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\UnlockService;
use FluentPlayer\Framework\Support\Arr;

/**
 * Public admin-ajax endpoint that verifies a media password and, on success,
 * sets the HttpOnly per-media unlock cookie (no token/HTML in the response, so
 * nothing sensitive is exposed to JS). The frontend just reloads; the server
 * then renders the real player when the cookie token validates.
 */
class UnlockHandler
{
    public function register()
    {
        add_action('wp_ajax_fluent_player_unlock', [$this, 'handle']);
        add_action('wp_ajax_nopriv_fluent_player_unlock', [$this, 'handle']);

        return $this;
    }

    public function handle()
    {
        if (!check_ajax_referer('fluent_player_frontend', 'nonce', false)) {
            wp_send_json_error(['code' => 'bad_nonce', 'message' => __('Session expired. Please refresh and try again.', 'fluent-player')], 403);
        }

        $id   = absint(Arr::get($_POST, 'id'));
        $post = $id ? get_post($id) : null;

        $unlockableTypes = (array) apply_filters('fluent_player/unlockable_post_types', [Media::$postType]);

        if (!$post || !in_array($post->post_type, $unlockableTypes, true)) {
            wp_send_json_error(['code' => 'not_found', 'message' => __('Media not found.', 'fluent-player')], 404);
        }

        if (!post_password_required($post)) {
            wp_send_json_success();
        }

        $password = (string) wp_unslash(Arr::get($_POST, 'password', ''));
        if ($password === '') {
            wp_send_json_error(['code' => 'password_required', 'message' => __('Please enter the password.', 'fluent-player')], 400);
        }

        if (UnlockService::isRateLimited($id)) {
            wp_send_json_error(['code' => 'rate_limited', 'message' => __('Too many attempts. Please try again later.', 'fluent-player')], 429);
        }

        if (!UnlockService::verifyPassword($post, $password)) {
            UnlockService::bumpRateLimit($id);
            wp_send_json_error(['code' => 'incorrect', 'message' => __('Incorrect password.', 'fluent-player')], 403);
        }

        UnlockService::sendUnlockCookie($post);
        wp_send_json_success();
    }
}
