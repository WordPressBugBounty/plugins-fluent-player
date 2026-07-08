<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\Progression\ProgressionService;
use FluentPlayer\Framework\Support\Arr;

/**
 * Logged-in admin-ajax endpoint that receives raw watched segments for a media
 * and returns a completion verdict. LMS-agnostic: it records the evidence and
 * fires fluent_player/watch_recorded so an adapter (LearnDash first) can mark a
 * step complete. No nopriv variant — completion is always per-user.
 */
class ProgressionHandler
{
    public function register()
    {
        add_action('wp_ajax_fluent_player_progression', [$this, 'handle']);

        return $this;
    }

    public function handle()
    {
        if (!check_ajax_referer('fluent_player_frontend', 'nonce', false)) {
            wp_send_json_error(['code' => 'bad_nonce', 'message' => __('Session expired. Please refresh and try again.', 'fluent-player')], 403);
        }

        $userId = get_current_user_id();
        if (!$userId) {
            wp_send_json_error(['code' => 'not_logged_in', 'message' => __('You must be logged in to track course progress.', 'fluent-player')], 401);
        }

        $mediaId = absint(Arr::get($_POST, 'media_id'));
        $post = $mediaId ? get_post($mediaId) : null;
        if (!$post || $post->post_type !== Media::$postType) {
            wp_send_json_error(['code' => 'not_found', 'message' => __('Media not found.', 'fluent-player')], 404);
        }

        $segments = json_decode((string) wp_unslash(Arr::get($_POST, 'segments', '[]')), true);
        if (!is_array($segments)) {
            $segments = [];
        }

        // Bound client-supplied work: a real session never produces thousands of intervals.
        if (count($segments) > 5000) {
            $segments = array_slice($segments, 0, 5000);
        }

        $mediaSettings = Media::getMediaSettings($mediaId);
        $serverDuration = (float) Arr::get($mediaSettings, 'duration', 0);
        if ($serverDuration <= 0) {
            // External providers (Bunny/Mux/Gumlet) store it under <provider>.duration.
            $provider = (string) Arr::get($mediaSettings, 'provider', '');
            $serverDuration = (float) Arr::get($mediaSettings, $provider . '.duration', 0);
        }
        $resolved = ProgressionService::pickDuration($serverDuration, Arr::get($_POST, 'duration', 0));

        $evidence = [
            'segments'            => $segments,
            'duration'            => $resolved['duration'],
            'durationSource'      => $resolved['source'],
            'ended'               => filter_var(Arr::get($_POST, 'ended', false), FILTER_VALIDATE_BOOLEAN),
            'mutedAutoplayActive' => filter_var(Arr::get($_POST, 'muted_autoplay', false), FILTER_VALIDATE_BOOLEAN),
        ];

        $context = [
            'course_id' => absint(Arr::get($_POST, 'course_id')),
            'step_id'   => absint(Arr::get($_POST, 'step_id')),
        ];

        $verdict = ProgressionService::record($mediaId, $userId, $evidence, $context);

        wp_send_json_success([
            'verdict'        => $verdict,
            'durationSource' => $resolved['source'],
        ]);
    }
}
