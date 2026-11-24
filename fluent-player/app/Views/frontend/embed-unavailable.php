<?php
/**
 * Embed unavailable — served at <permalink>/embed/ for a video or playlist that is not shared.
 *
 * @var string $title
 * @var string $permalink
 * @var string $message Optional; defaults to the video wording.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($title); ?></title>
    <style>
        html, body { margin: 0; height: 100%; background: #111; color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .fp-embed-unavailable { box-sizing: border-box; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px; text-align: center; }
        .fp-embed-unavailable p { margin: 0; font-size: 16px; line-height: 1.5; }
        .fp-embed-unavailable a { color: #fff; font-size: 14px; text-underline-offset: 3px; }
    </style>
</head>
<body>
    <main class="fp-embed-unavailable">
        <p><?php echo esc_html(!empty($message) ? $message : __('This video can\'t be embedded.', 'fluent-player')); ?></p>
        <a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener"><?php
            /* translators: %s: site name */
            echo esc_html(sprintf(__('Watch on %s', 'fluent-player'), get_bloginfo('name')));
        ?></a>
    </main>
</body>
</html>
