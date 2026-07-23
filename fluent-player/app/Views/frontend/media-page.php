<?php
if (!defined('ABSPATH')) exit;

/**
 * @var int $mediaId
 * @var \FluentPlayer\Framework\Foundation\Application $app
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\MediaRenderer;

$media = Media::find($mediaId);

// If media not found, show 404
if (!$media) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);

    $template_404 = get_query_template('404');
    if ($template_404) {
        include($template_404);
    }
    exit;
}
$isPublished = $media->post_status === 'publish';
$hasAccess = current_user_can('read_post', $media->ID);

if (!$isPublished && !$hasAccess) {
    global $wp_query;
    $wp_query->set_404();
    status_header(403);
    exit;
}

// Gate before rendering so a locked media's src never reaches page JS.
if (post_password_required($mediaId)) {
    get_header();
    echo '<div class="fluent-player-media-page"><div class="fluent-player-media-container fp-media-locked fp-media-password-required">';
    echo get_the_password_form($mediaId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated form markup
    echo '</div></div>';
    get_footer();
    exit;
}

// Rendered before get_header() so the renderer's enqueues and script
// localization land in the document head.
$playerHtml = MediaRenderer::renderFromPost($mediaId);

get_header();
?>

<div class="fluent-player-media-page">
    <div class="fluent-player-media-container">
        <h1 class="fluent-player-media-title"><?php echo esc_html($media->post_title); ?></h1>
        <?php echo $playerHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output is escaped at build time ?>
    </div>
</div>

<?php
get_footer();
