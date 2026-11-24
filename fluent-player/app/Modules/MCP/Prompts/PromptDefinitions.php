<?php

namespace FluentPlayer\App\Modules\MCP\Prompts;

use FluentPlayer\App\Helpers\Helper;

/**
 * Ready-made MCP prompts. Each one is offered only while every tool it names is live.
 */
class PromptDefinitions
{
    public static function definitions()
    {
        $authoring = Helper::authoringCapability();

        return [
            'fluent-player/audit-my-videos' => [
                'label'            => __('Audit my videos', 'fluent-player'),
                'description'      => __('Find accessibility problems across your media and get a fix list.', 'fluent-player'),
                'capability'       => $authoring,
                'requires_tools'   => ['fluent-player/audit-media-accessibility'],
                'input_schema'     => ['type' => 'object'],
                'execute_callback' => function () {
                    return self::message(
                        "Audit my Fluent Player media for accessibility.\n"
                        . "1. Call fluent-player-audit-media-accessibility (page through results while meta.page.has_more is true).\n"
                        . "2. Group the issues by severity, high first, and list each media title with its fixes.\n"
                        . "3. Do not change anything; end with the three fixes that help the most viewers."
                    );
                },
            ],
            'fluent-player/find-drop-off-and-suggest-cta' => [
                'label'            => __('Find where viewers drop off and suggest a CTA', 'fluent-player'),
                'description'      => __('Estimate from average watch percentage where viewers of your top videos tend to stop, and suggest where a call-to-action or email capture would catch them first.', 'fluent-player'),
                'capability'       => 'manage_options',
                'requires_tools'   => ['fluent-player/get-analytics'],
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => ['media_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional; defaults to your top videos.']],
                ],
                'execute_callback' => function ($params = []) {
                    $target = !empty($params['media_id'])
                        ? 'media #' . (int) $params['media_id']
                        : 'my top 5 videos (fluent-player-get-analytics with scope "top")';

                    return self::message(
                        "Find where viewers tend to stop watching {$target}.\n"
                        . "1. Call fluent-player-get-analytics with scope \"retention\" for each video.\n"
                        . "2. The curve is an estimate from the average percentage watched, not per-second data: estimate the point by which most viewers have left, and say it is an estimate.\n"
                        . "3. Suggest a call-to-action overlay or email capture a little before that point, with the text to show.\n"
                        . "4. Do not apply anything. If I approve and AI changes are allowed, add it with fluent-player-upsert-overlay or fluent-player-upsert-layer (dry_run first)."
                    );
                },
            ],
            'fluent-player/set-up-video-from-url' => [
                'label'            => __('Set up a new video from a URL', 'fluent-player'),
                'description'      => __('Create a player from a YouTube, Vimeo, Dailymotion or direct URL, pick a preset, and get the embed code.', 'fluent-player'),
                'capability'       => $authoring,
                'requires_tools'   => ['fluent-player/create-media-from-url', 'fluent-player/list-presets'],
                'input_schema'     => [
                    'type'       => 'object',
                    'required'   => ['url'],
                    'properties' => ['url' => ['type' => 'string', 'description' => 'YouTube, Vimeo, Dailymotion or direct media URL.']],
                ],
                'execute_callback' => function ($params = []) {
                    $url = esc_url_raw(isset($params['url']) ? $params['url'] : '');

                    return self::message(
                        "Set up a Fluent Player video for {$url}.\n"
                        . "1. Call fluent-player-list-presets and pick the default preset unless I ask for another.\n"
                        . "2. Call fluent-player-create-media-from-url with dry_run: true and show me the preview.\n"
                        . "3. After I approve, repeat the call with the confirm_token. It is saved as a draft.\n"
                        . "4. Give me the shortcode and block markup from its embed field."
                    );
                },
            ],
            'fluent-player/match-player-to-site-colors' => [
                'label'            => __('Match the player to my site colours', 'fluent-player'),
                'description'      => __('Read your theme\'s colours and suggest player brand, icon and button colours that match them.', 'fluent-player'),
                'capability'       => 'manage_options',
                'requires_tools'   => ['fluent-player/get-site-colors', 'fluent-player/get-global-settings'],
                'input_schema'     => ['type' => 'object'],
                'execute_callback' => function () {
                    return self::message(
                        "Match the Fluent Player branding to my site colours.\n"
                        . "1. Call fluent-player-get-site-colors and fluent-player-get-global-settings. If no site colours are found, ask me for my brand colour instead.\n"
                        . "2. Pick: brand_color = the site button background, else the link colour, else the first strong palette colour; play_button_color (the play icon) = the same colour; keep play_button_bg_color and control_bar_color as they are, so the player keeps its own look.\n"
                        . "3. The play icon must have at least 3:1 contrast against play_button_bg_color; if not, use a darker site palette colour that passes, else keep the current play_button_color.\n"
                        . "4. Show me a table of each colour, current value and new value.\n"
                        . "5. After I approve: if AI changes are allowed, save the branding section with fluent-player-update-global-settings (dry_run: true first, then repeat with the confirm_token). Otherwise tell me to set them in Fluent Player → Settings → Branding.\n"
                        . "6. Say that media and playlists with their own branding keep their colours."
                    );
                },
            ],
        ];
    }

    public static function outputSchema()
    {
        return [
            'type'       => 'object',
            'required'   => ['messages'],
            'properties' => ['messages' => ['type' => 'array']],
        ];
    }

    private static function message($text)
    {
        return [
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]],
            ],
        ];
    }
}
