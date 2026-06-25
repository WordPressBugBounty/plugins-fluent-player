<?php
/**
 * YouTube Subscribe Button Overlay
 * 
 * @var array $settings (for single player)
 * @var object $media (for playlist - has ->settings property)
 * @var array $default_settings
 * @var int $media_id
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\Framework\Support\Arr;

if (!defined('ABSPATH')) exit;

$mediaSettings = isset($media) && is_object($media) ? $media->settings : (isset($settings) ? $settings : []);

if (isset($default_settings) && is_array($default_settings)) {
    $youtubeSettings = Arr::get($default_settings, 'youtube', []);
    $showSubscribeButton = Arr::get($youtubeSettings, 'showSubscribeButton', false);
} else {
    $globalSettings = \FluentPlayer\App\Services\SettingsService::getSettings();
    $youtubeSettings = Arr::get($globalSettings, 'youtube', []);
    $showSubscribeButton = Arr::get($youtubeSettings, 'show_subscribe_button', false);
}

$isYouTubeVideo = false;
$youtubeVideoId = null;
$originalVideoSrc = Arr::get($mediaSettings, 'src', '');
if ($originalVideoSrc) {
    if (strpos($originalVideoSrc, 'youtube.com') !== false || 
        strpos($originalVideoSrc, 'youtube-nocookie.com') !== false || 
        strpos($originalVideoSrc, 'youtu.be') !== false) {
        $isYouTubeVideo = true;
        preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|live\/|shorts\/|watch\?v=)|youtube-nocookie\.com\/(?:embed\/|v\/|watch\?v=))([^&\n?#]+)/', $originalVideoSrc, $matches);
        if (!empty($matches[1])) {
            $youtubeVideoId = $matches[1];
        }
    }
}
if (!$isYouTubeVideo || !$showSubscribeButton || !$youtubeVideoId) {
    return;
}
?>

<div class="fluent-player-youtube-subscribe-overlay">
    <a
        href="https://www.youtube.com/watch?v=<?php echo esc_attr($youtubeVideoId); ?>&sub_confirmation=1"
        target="_blank"
        rel="noopener noreferrer"
        class="fluent-youtube-subscribe-button"
        aria-label="<?php echo esc_attr__('Subscribe on YouTube', 'fluent-player'); ?>"
    >
        <span class="youtube-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
            </svg>
        </span>
        <span class="youtube-text"><?php esc_html_e('Subscribe', 'fluent-player'); ?></span>
    </a>
</div>

