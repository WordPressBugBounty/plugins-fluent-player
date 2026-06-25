<?php
if (!defined('ABSPATH')) exit;

/**
 * @var int $mediaId
 * @var \FluentPlayer\Framework\Foundation\Application $app
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\App\Services\Translations\TransStrings;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;
use FluentPlayer\Framework\Support\Arr;

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
$hasAccess = current_user_can('manage_options');

if (!$isPublished && !$hasAccess) {
    global $wp_query;
    $wp_query->set_404();
    status_header(403);
    exit;
}

Enqueue::script(
    'fluent_player',
    'js/fluent-player.js',
    [],
    FLUENT_PLAYER_VERSION,
    true
);

$mediaData = MediaService::prepareMediaForFrontend($media, 'media-page');
$media = $mediaData['media'];

// Apply signed URLs, DRM tokens, analytics keys before exposing to frontend
$media->settings = apply_filters('fluent_player/player_settings', $media->settings);

$media_var_name = 'fluent_player_' . $media->ID;
$mediaData['analytics_nonce'] = wp_create_nonce('fluent_player_track_event:' . $media->ID);

wp_localize_script('fluent_player', $media_var_name, $mediaData);

$settings = Helper::getSettings();
$analytics = Arr::get($settings, 'analytics', []);
$googleAnalytics = Arr::get($settings, 'google_analytics', []);
$youtubeSettings = Arr::get($settings, 'youtube', []);
$performanceSettings = Arr::get($settings, 'performance', []);
$resumePlayback = false;
if (Helper::hasPro()) {
    $resumePlayback = Arr::get($settings, 'general.resume_playback', false);
}

wp_localize_script('fluent_player', 'fluent_player', [
    'ajax_url'         => admin_url('admin-ajax.php'),
    'nonce'            => wp_create_nonce('fluent_player_frontend'),
    'serverLang'       => $mediaData['serverLang'],
    'has_pro'          => Helper::hasPro(),
    'analytics'        => $analytics,
    'google_analytics' => $googleAnalytics,
    'youtube'          => [
        'privacy_mode'          => Arr::get($youtubeSettings, 'privacy_mode', false),
        'show_subscribe_button' => Arr::get($youtubeSettings, 'show_subscribe_button', false),
    ],
    'resume_playback' => $resumePlayback,
    'dynamic_load_js'     => Arr::get($performanceSettings, 'dynamic_load_js', false),
    'trans'            => TransStrings::getFrontendStrings()
]);

$defaultSettings = $mediaData['default_settings'];

// Get the header
get_header();
?>

<div class="fluent-player-media-page">
    <div class="fluent-player-media-container">
        <h1 class="fluent-player-media-title"><?php echo esc_html($media->post_title); ?></h1>
        <?php
        $app->view->render('player', [
            'media_id'         => $media->ID,
            'media_var_name'   => $media_var_name,
            'settings'         => $media->settings,
            'default_settings' => $defaultSettings
        ]);
        ?>
    </div>
</div>

<?php
get_footer();
