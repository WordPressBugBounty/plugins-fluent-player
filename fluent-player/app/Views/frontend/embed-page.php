<?php
/**
 * Embed page — the bare player served at <permalink>/embed/ for a shared video.
 *
 * @var string $title
 * @var string $content Rendered player HTML.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?></title>
    <?php wp_head(); ?>
    <style>
        html, body { margin: 0; padding: 0; background: transparent; }
        /* Player and playlist styles set outer margins; this page holds only one player. */
        .fp-embed-page > * { box-sizing: border-box; width: 100% !important; max-width: none !important; margin: 0 !important; }
    </style>
</head>
<body class="fp-embed-body">
    <main class="fluent-player-media-page fp-embed-page">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block render callbacks handle escaping
        echo $content;
        ?>
    </main>
    <?php wp_footer(); ?>
    <?php
    $fpAdminUrlParts = wp_parse_url(admin_url());
    $fpEditorOrigin = isset($fpAdminUrlParts['scheme'], $fpAdminUrlParts['host'])
        ? $fpAdminUrlParts['scheme'] . '://' . $fpAdminUrlParts['host'] . (isset($fpAdminUrlParts['port']) ? ':' . $fpAdminUrlParts['port'] : '')
        : '';
    ?>
    <script>
    (function () {
        if (window.parent === window) {
            return;
        }
        var editorOrigin = <?php echo wp_json_encode($fpEditorOrigin); ?>;
        // A host may append its own #?secret= after an existing one; core's wp-embed only accepts the last.
        var match = window.location.hash.match(/.*secret=([A-Za-z0-9]+)/);
        var secret = match ? match[1] : '';
        function sendHeight() {
            window.parent.postMessage({ message: 'height', value: Math.ceil(document.body.getBoundingClientRect().height), secret: secret }, '*');
        }
        window.addEventListener('message', function (event) {
            if (event.data && event.data.message === 'ready') {
                secret = event.data.secret || secret;
                sendHeight();
            }
            // Only this site's own block editor may pause; public embeds ignore other sites.
            if ((event.origin === window.location.origin || event.origin === editorOrigin) && event.data && event.data.message === 'fluent-player-pause') {
                document.querySelectorAll('media-player').forEach(function (player) { player.pause(); });
            }
        });
        var query = new URLSearchParams(window.location.search);
        var previewMediaId = query.has('fluent_player_preview') ? query.get('media_id') : '';
        if (previewMediaId && editorOrigin) {
            document.addEventListener('duration-change', function (event) {
                var player = event.composedPath()[0];
                var container = player && player.closest ? player.closest('.fluent-player-container') : null;
                var duration = Number(event.detail);
                if (!container || container.getAttribute('data-media-id') !== previewMediaId || !(duration > 1) || !isFinite(duration)) {
                    return;
                }
                window.parent.postMessage({ message: 'fluent-player-duration', mediaId: Number(previewMediaId), value: duration }, editorOrigin);
            }, true);
        }
        window.addEventListener('load', sendHeight);
        if (window.ResizeObserver) {
            new ResizeObserver(sendHeight).observe(document.body);
        }
    })();
    </script>
</body>
</html>
