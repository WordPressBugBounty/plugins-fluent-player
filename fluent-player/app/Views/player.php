<?php
/**
 * @var array $settings
 * @var array $controls
 * @var array $behaviors
 * @var int $media_id
 * @var string $instance_id
 * @var string $media_var_name
 * @var string $skin
 * @var array $default_settings
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Helpers\Helper;

if (!defined('ABSPATH')) exit;

$instance_id = $instance_id ?? $media_id;
?>

<?php
$skin = Arr::get($settings, 'skin', 'modern');
$controls = Arr::get($settings, 'controls', []);
$behaviors = Arr::get($settings, 'behaviors', []);
$videoEndOption = Arr::get($settings, 'video_end_option', 'default');
$default_settings = $default_settings ?? [];
$preload = Arr::get($settings, 'preload', Arr::get($default_settings, 'preload', 'metadata'));
$allowedPreloads = ['none', 'metadata', 'auto'];
$preload = in_array($preload, $allowedPreloads, true) ? $preload : '';
$viewType = Arr::get($settings, 'viewType', 'video');
$isAudio = ($viewType === 'audio');

$videoSrc = Arr::get($settings, 'src', '');
$useCrossOrigin = true;
if (Arr::has($settings, 'crossorigin')) {
    $useCrossOrigin = Arr::isTrue($settings, 'crossorigin');
}
$isYouTubeSource = (bool) (
    $videoSrc &&
    (
        strpos($videoSrc, 'youtube.com') !== false ||
        strpos($videoSrc, 'youtu.be') !== false ||
        strpos($videoSrc, 'youtube-nocookie.com') !== false
    )
);

// YouTube storyboard sprites do not expose ACAO headers. If we force crossorigin here,
// Vidstack inherits it onto hover thumbnail images and the browser blocks them.
if ($isYouTubeSource) {
    $useCrossOrigin = false;
}

// Bunny Storage: Prefer CDN URL for public access; fallback to streaming URL
$provider = Arr::get($settings, 'provider', '');
if ($provider === 'bunny_storage') {
    $cdnUrl = Arr::get($settings, 'bunny_storage.url');
    if ($cdnUrl) {
        $videoSrc = $cdnUrl;
    } elseif (empty($videoSrc)) {
        $videoSrc = Arr::get($settings, 'bunny_storage.streamingUrl', '');
    }
}

// Mux: Auto-generate playback URL, poster, and storyboard from playback ID
if ($provider === 'mux' || $provider === 'mux_stream') {
    $muxPlaybackId = Arr::get($settings, 'mux.playback_id', '');
    if ($muxPlaybackId) {
        if (empty($videoSrc)) {
            $videoSrc = 'https://stream.mux.com/' . $muxPlaybackId . '.m3u8';
        }
        if (empty(Arr::get($settings, 'posterSrc'))) {
            $settings['posterSrc'] = 'https://image.mux.com/' . $muxPlaybackId . '/thumbnail.jpg?width=1280&fit_mode=smartcrop';
        }
        if (empty(Arr::get($settings, 'thumbnails'))) {
            $settings['thumbnails'] = 'https://image.mux.com/' . $muxPlaybackId . '/storyboard.vtt';
        }
    }
}

// YouTube Privacy-Enhanced Mode. The player embed host is handled at runtime via the
// Vidstack provider's `cookies` flag (resources/js/utils/videoSrc.js → applyYouTubePrivacyMode);
// Vidstack ignores the host in the source URL, so the src stays the original watch URL.
// The poster, however, is fetched on page load (before playback), so its img.youtube.com
// host is swapped here for the cookieless i.ytimg.com when privacy mode is on. Rewriting
// $settings['posterSrc'] once covers every poster render below (media-poster, audio bar,
// audio container background).
$ytPrivacyMode = (bool) Arr::get($default_settings, 'youtube.privacyMode', false);
$settings['posterSrc'] = Helper::privacyEnhanceYouTubePoster(
    Arr::get($settings, 'posterSrc', ''),
    $ytPrivacyMode
);

$player_ref = $player_ref ?? ('fpm_' . absint($media_id) . '_' . sanitize_key((string) $instance_id));
?>

<?php
$mediaAspectRatio = Arr::get($settings, 'aspectRatio', 'default');
$effectiveAspectRatio = ($mediaAspectRatio && $mediaAspectRatio !== 'default')
    ? $mediaAspectRatio
    : Arr::get($default_settings, 'aspectRatio', '16:9');
$cssAspectRatio = ($effectiveAspectRatio && $effectiveAspectRatio !== 'original')
    ? str_replace(':', '/', $effectiveAspectRatio)
    : '16/9';

$isShort = Arr::isTrue($settings, 'is_short');
$isPortrait = false;
if ($effectiveAspectRatio && $effectiveAspectRatio !== 'original' && $effectiveAspectRatio !== 'default') {
    $ratioParts = explode(':', $effectiveAspectRatio);
    if (count($ratioParts) === 2) {
        $isPortrait = intval($ratioParts[0]) < intval($ratioParts[1]);
    }
}
$isMutedBgPlayer = Arr::get($settings, 'mutedAutoplay')
    && Arr::isTrue($behaviors, 'hide_center_controls')
    && Arr::isTrue($behaviors, 'hide_bottom_controls');
?>
<?php if ($isAudio): ?>
<style>.fp-audio .fluent-player-container{height:auto;background-color:transparent}.fp-audio media-player:not([data-view-type='audio']) .fp-media-controls-bottom,.fp-audio media-player:not([data-view-type='audio']) media-provider{display:none}</style>
<?php else: ?>
<style>
#fluent_player_<?php echo esc_attr($instance_id); ?>{aspect-ratio:<?php echo esc_attr($cssAspectRatio); ?>}
#fluent_player_<?php echo esc_attr($instance_id); ?> .fluent-player-container{position:relative;overflow:hidden}
.fluent-player-loader{position:absolute;inset:0;z-index:2;background:#000;transition:opacity .3s}
.fluent-player-loader.is-hidden{opacity:0;pointer-events:none}
.fluent-player-loader-poster{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.fluent-player-loader-overlay{position:absolute;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:2}
media-player:not([data-can-play]):not([load="play"]){opacity:0;pointer-events:none}
<?php if ($isMutedBgPlayer && empty($settings['layers'])): ?>
#fluent_player_<?php echo esc_attr($instance_id); ?> media-player{pointer-events:none;cursor:default}
<?php endif; ?>
</style>
<?php endif; ?>
<div class="fluent-player<?php echo $isAudio ? ' fp-audio' : ''; ?><?php echo $isPortrait ? ' fp-portrait' : ''; ?><?php echo $isShort ? ' fp-short' : ''; ?>" id="fluent_player_<?php
echo esc_attr($instance_id); ?>" data-var_name="<?php
echo esc_attr($media_var_name); ?>" data-skin="<?php
echo esc_attr($skin); ?>" data-flp-ref="<?php echo esc_attr($player_ref); ?>" role="region" aria-label="<?php echo esc_attr__('Media player', 'fluent-player'); ?>">
    <div class="fluent-player-container" data-media-id="<?php echo esc_attr($media_id); ?>"<?php if ($isAudio): ?> style="height:auto<?php if (Arr::get($settings, 'posterSrc') && Arr::get($settings, 'show_poster', true)): ?>;background-image:url('<?php echo esc_url(Arr::get($settings, 'posterSrc')); ?>');background-size:cover;background-position:center<?php endif; ?>"<?php endif; ?>>
        <media-player
                title="<?php
                echo esc_attr(Arr::get($settings, 'title', '')); ?>"
                src="<?php
                echo esc_url($videoSrc); ?>"
                <?php
            if ($useCrossOrigin) {
                echo esc_attr('crossorigin');
            } ?>
            <?php if ($preload): ?>
                preload="<?php echo esc_attr($preload); ?>"
            <?php endif; ?>
            <?php
            if (Arr::get($settings, 'autoplay') || Arr::get($settings, 'mutedAutoplay')) {
                echo esc_attr('autoplay');
            } ?>
            <?php
            if (Arr::get($settings, 'playsInline')) {
                echo esc_attr('playsinline');
            } ?>
            <?php
            if (Arr::get($settings, 'muted') || Arr::get($settings, 'mutedAutoplay')) {
                echo esc_attr('muted');
            } ?>

                stream-type="<?php echo esc_attr(Arr::get($settings, 'streamType', 'on-demand')); ?>"
                media-type="<?php
                echo esc_attr(Arr::get($settings, 'mediaType', 'video')); ?>"
                view-type="<?php
                echo esc_attr(Arr::get($settings, 'viewType', 'video')); ?>"
                load="<?php
                $loadStrategy = Arr::get($settings, 'loadStrategy', 'visible');
                echo esc_attr($loadStrategy === 'poster' ? 'play' : $loadStrategy); ?>"
                <?php
                if ($videoEndOption === 'loop' && !Arr::get($settings, 'mutedAutoplay')): ?>
                    loop
                <?php endif; ?>
        >
            <media-provider>
                <?php if (!$isAudio): ?>
                <media-poster
                        src="<?php
                        echo esc_url(Arr::get($settings, 'posterSrc', '')); ?>"
                        alt="<?php
                        echo esc_attr(Arr::get($settings, 'title', '')); ?>"
                        <?php
                        $loadStrategy = Arr::get($settings, 'loadStrategy', 'visible');
                        if (in_array($loadStrategy, ['play', 'poster'], true) && Arr::get($settings, 'posterSrc')): ?>
                            load="eager"
                        <?php endif; ?>
                ></media-poster>
                <?php endif; ?>

                <?php
                if (Arr::get($settings, 'chapters')): ?>
                    <?php
                    include __DIR__ . '/player/chapters-track.php'; ?>
                <?php
                endif; ?>

                <?php include __DIR__ . '/player/subtitle-tracks.php'; ?>
            </media-provider>


            <?php include __DIR__ . '/player/email-capture.php'; ?>
            <?php include __DIR__ . '/player/cta-overlay.php'; ?>
            <?php include __DIR__ . '/player/action-bar-overlay.php'; ?>
            <?php include __DIR__ . '/player/overlays.php'; ?>
            <?php if (!Arr::get($settings, 'mutedAutoplay') || !Arr::isTrue($behaviors, 'hide_center_controls')): ?>
                <?php include __DIR__ . '/player/gestures.php'; ?>
            <?php endif; ?>
            <?php include __DIR__ . '/player/captions.php'; ?>

            <media-announcer style="display:none"></media-announcer>

            <div class="vds-scrim"></div>

            <?php
            include __DIR__ . '/player/logo.php'; ?>
            <?php
            include __DIR__ . '/player/youtube-subscribe.php'; ?>

            <?php
            // Render layers data inside media-player so they are visible in fullscreen
            if (!empty($settings['layers']) && class_exists('FluentPlayer\App\Views\Layers\LayerRenderer')) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML content is escaped within the view
                echo \FluentPlayer\App\Views\Layers\LayerRenderer::renderLayersData($settings, \absint($media_id));
            }
            ?>

            <?php
            // Audio players use a different layout
            if ($isAudio):
                // Audio layout with integrated controls (minimal shows play button only)
                if (!Arr::isTrue($behaviors, 'hide_bottom_controls')):
                    include __DIR__ . '/player/bottom-controls-audio.php';
                endif;
            else:
                // Video player controls
                // Top title overlay and language controls are now part of top-controls.php
                if ($skin !== 'minimal' && !Arr::isTrue($behaviors, 'hide_top_controls')):
                    include __DIR__ . '/player/top-controls.php';
                endif;

                if (!Arr::isTrue($behaviors, 'hide_center_controls')):
                    include __DIR__ . '/player/center-controls.php';
                endif;

                if ($skin !== 'minimal' && !Arr::isTrue($behaviors, 'hide_bottom_controls')):
                    include __DIR__ . '/player/bottom-controls.php';
                endif;
            endif; ?>


        </media-player>

        <?php if (!$isAudio): ?>
        <!-- Loader element with poster image for better LCP -->
        <div id="fluent_player_loader_<?php echo esc_attr($instance_id); ?>"
             class="fluent-player-loader<?php
                $loadStrategy = Arr::get($settings, 'loadStrategy', 'visible');
                // For load="play", hide loader initially (poster + controls visible)
                echo in_array($loadStrategy, ['play', 'poster'], true) ? ' is-hidden' : '';
             ?>">
            <?php if (Arr::get($settings, 'posterSrc')): ?>
            <img class="fluent-player-loader-poster"
                 src="<?php echo esc_url(Arr::get($settings, 'posterSrc')); ?>"
                 alt="<?php echo esc_attr(Arr::get($settings, 'title', '')); ?>"
                 loading="eager"
                 fetchpriority="high">
            <?php endif; ?>
            <div class="fluent-player-loader-overlay" aria-hidden="true">
                <div class="fluent-player-loader-spinner"></div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
