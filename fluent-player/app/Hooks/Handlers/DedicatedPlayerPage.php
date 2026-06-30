<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\MediaRenderer;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\Framework\Support\Arr;

class DedicatedPlayerPage
{
    private static $post_type = 'fluent_player_media';

    /**
     * Handle template redirect for single fluent_player_media posts.
     * Renders the dedicated player page or returns 404 for private media.
     */
    public function handleTemplateRedirect()
    {
        if (!is_single()) {
            return;
        }

        global $post;

        if (!isset($post)) {
            return;
        }

        if (self::$post_type !== $post->post_type) {
            return;
        }

        $isPublic = $post->post_status === 'publish';

        // Allow users who can read the post to view it (read_post handles
        // private via read_private_posts, draft/pending via the edit cap).
        if (current_user_can('read_post', $post->ID)) {
            $this->renderPage($post, $isPublic);
            exit();
        }

        if (!$isPublic) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            get_template_part(404);
            exit();
        }

        // Public media, render dedicated player page
        $this->renderPage($post, $isPublic);
        exit();
    }

    /**
     * Conditionally enqueue styles and scripts only when needed
     */
    public function enqueueConditionalStyles()
    {
        if (is_singular(self::$post_type)) {
            $this->enqueueStyles();
            $this->enqueueScripts();
        }
    }

    /**
     * Render the dedicated player page with minimal layout
     */
    private function renderPage($post, $isPublic)
    {
        $parsedTitle = MediaService::parseSmartcodes($post->post_title, ['media' => $post]);
        $statusLabels = [
            'private' => __('Private', 'fluent-player'),
            'draft'   => __('Draft', 'fluent-player'),
            'pending' => __('Pending', 'fluent-player'),
            'future'  => __('Scheduled', 'fluent-player'),
        ];
        $statusLabel = Arr::get($statusLabels, $post->post_status, __('Private', 'fluent-player'));
        $scheduledTime = '';
        if ($post->post_status === 'future') {
            $scheduledTime = wp_date(get_option('date_format') . ' ' . get_option('time_format'), get_post_timestamp($post));
        }
        ?><!DOCTYPE html>
        <html <?php language_attributes(); ?> class="fluent-player-html">
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($parsedTitle); ?> - <?php bloginfo('name'); ?></title>
            <?php wp_head(); ?>
        </head>
        <body>
            <div class="fluent-player-dedicated-page">
                <header class="fluent-player-header">
                    <h1 class="fluent-player-header-title">
                        <span class="fluent-player-header-title-text">
                            <?php echo esc_html($parsedTitle); ?>
                        </span>
                        <?php if (!$isPublic) : ?>
                            <span class="fluent-player-header-title-status">
                                <?php echo esc_html($statusLabel); ?>
                                <?php if ($scheduledTime) : ?>
                                    <span class="fluent-player-header-title-time">· <?php echo esc_html($scheduledTime); ?></span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </h1>
                </header>

                <main class="fluent-player-media-container">
                    <?php
                    // Render through the block pipeline so timed content InnerBlocks are included.
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block render callbacks handle escaping
                    echo MediaRenderer::renderFromPost(absint($post->ID));
                    ?>
                </main>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html><?php
    }

    /**
     * Register and enqueue dedicated player page styles
     */
    private function enqueueStyles()
    {
        wp_register_style('fluent-player-dedicated-page', false, [], FLUENT_PLAYER_VERSION);
        wp_enqueue_style('fluent-player-dedicated-page');

        $css = '
            html.fluent-player-html,
            html.fluent-player-html body {
                width: 100%; height: 100%; margin: 0; padding: 0;
                background-color: #000;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                overflow-x: hidden;
            }
            html.fluent-player-html .fluent-player-media-container { opacity: 1; }

            .fluent-player-dedicated-page {
                display: grid; height: 100%;
                grid-template-rows: 62px auto;
                background-color: #f5f3f0;
                position: relative;
            }
            .fluent-player-header {
                display: flex; gap: 2em; align-items: center; justify-content: center;
                background: #2e2e2e; color: #fff; padding: 15px;
                z-index: 9; position: relative; max-width: 100vw; box-sizing: border-box;
            }
            .fluent-player-header-title {
                color: #fff; font-size: 15px; font-weight: 600; line-height: 18px;
                display: flex; align-items: center; gap: .5em;
                max-width: 100vw; box-sizing: border-box; padding: 0 1rem;
            }
            .fluent-player-header-title-text {
                overflow: hidden; text-overflow: ellipsis;
                display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            }
            .fluent-player-header-title-status {
                display: inline-flex; align-items: center; border: none; white-space: nowrap;
                user-select: none; font-weight: 700; border-radius: 9999px;
                padding: 0 .5em; background: grey; color: #000; line-height: 1.4; font-size: 12px;
            }

            .fluent-player-media-container {
                display: flex; flex-direction: column; align-items: center;
                width: 100%; margin: 0 auto; padding: 2rem; box-sizing: border-box;
                height: calc(100vh - 62px); overflow-y: auto; position: relative;
            }
            .fluent-player-media-container .fp-media-block {
                width: 100%;
                max-width: min(960px, calc((100vh - 100px) * 16 / 9));
                min-width: 480px;
                margin: 20px auto 0;
            }
            .fluent-player-media-container .alignwide,
            .fluent-player-media-container .alignfull {
                width: 100%;
                align-self: stretch;
            }
            .fluent-player-media-container .alignwide .fp-media-block {
                max-width: min(1340px, calc((100vh - 100px) * 16 / 9));
            }
            .fluent-player-media-container .alignfull {
                margin-left: -2rem;
                margin-right: -2rem;
                width: calc(100% + 4rem);
            }
            .fluent-player-media-container .alignfull .fp-media-block {
                max-width: 100%;
                min-width: 0;
                margin: 0;
            }
            .fluent-player-media-container .fp-media-block:has(.fp-audio) {
                margin-top: 30px;
            }
            .fluent-player-media-container .fp-timed-content-container {
                margin: 0 auto auto;
            }
            .fluent-player-media-container .fluent-player,
            .fluent-player-media-container .fluent-player-container {
                width: 100%;
            }
            .fluent-player-media-container .fluent-player:not(.fp-audio),
            .fluent-player-media-container .fluent-player:not(.fp-audio) .fluent-player-container {
                background: #000;
            }

            .fluent-player-dedicated-page media-player {
                width: 100% !important; opacity: 0; transition: opacity 0.3s ease-out;
            }
            html.player-ready media-player { opacity: 1; }

            @media (max-width: 768px) {
                .fluent-player-dedicated-page { grid-template-rows: auto auto; }
                .fluent-player-header { padding: 12px 16px; }
                .fluent-player-header-title { font-size: 14px; padding: 0; }
                .fluent-player-media-container { padding: 0 1rem; }
                .fluent-player-media-container .fp-media-block { min-width: 0; max-width: 100%; }
                .fluent-player-media-container .alignfull {
                    margin-left: -1rem;
                    margin-right: -1rem;
                    width: calc(100% + 2rem);
                }
            }
        ';

        wp_add_inline_style('fluent-player-dedicated-page', $css);
    }

    /**
     * Register and enqueue dedicated player page scripts
     */
    private function enqueueScripts()
    {
        wp_register_script(
            'fluent-player-single-media',
            false,
            [],
            FLUENT_PLAYER_VERSION,
            true
        );

        wp_enqueue_script('fluent-player-single-media');

        $custom_js = "
            document.addEventListener('DOMContentLoaded', function () {
                const players = document.querySelectorAll('media-player');
                if (players.length > 0) {
                    players.forEach(function (player) {
                        player.style.width = '100%';
                        player.addEventListener('can-play', function () {
                            document.documentElement.classList.add('player-ready');
                        });
                    });
                } else {
                    document.documentElement.classList.add('player-ready');
                }
            });
        ";

        wp_add_inline_script('fluent-player-single-media', $custom_js);
    }
}
