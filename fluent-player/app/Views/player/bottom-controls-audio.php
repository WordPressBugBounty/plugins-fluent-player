<?php

/**
 * Audio Bottom Controls - Skin-Aware Layout
 * @var string $skin
 * @var array $controls
 * @var array $settings
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

$hasChapters = !empty(Arr::get($settings, 'chapters', []));
$subtitles = Arr::get($settings, 'subtitles', []);
$hasSubtitles = !empty($subtitles);
$subtitleCount = is_array($subtitles) ? count($subtitles) : 0;
$audioSrc = Arr::get($settings, 'src', '');
$audioProvider = Arr::get($settings, 'provider', '');
$hasHls = ($audioProvider === 'bunny' || $audioProvider === 'mux' || $audioProvider === 'mux_stream' || strpos($audioSrc, '.m3u8') !== false);
$hasCaptionSource = $hasSubtitles || $hasHls;
$showSettingsMenu = Arr::get($controls, 'settings', true);
$presetSlug = Arr::get($settings, 'preset_slug', Arr::get($settings, 'slug', ''));
$isReservedPreset = in_array($presetSlug, ['default', 'course', 'simple', 'minimal', 'standard', 'floating', 'ambient'], true);
$isSimpleReservedPreset = ($presetSlug === 'simple');
$isSimpleSkin = ($skin === 'simple');
$useSettingsForSpeedControls = $isReservedPreset && $showSettingsMenu;
$showSettingsMenuControl = $showSettingsMenu && (
    !$isSimpleSkin ||
    ($isSimpleReservedPreset && $hasCaptionSource)
);
$captionsEnabled = Arr::get($controls, 'captions_toggle', true);
$canUseStandaloneCaptions = $captionsEnabled && $hasCaptionSource && !$showSettingsMenuControl;
$shouldUseCaptionMenu = $hasHls || $subtitleCount > 1;
$showStandaloneCaptionMenu = !$isReservedPreset && $canUseStandaloneCaptions && $shouldUseCaptionMenu;
$showStandaloneCaptionShortcut = $captionsEnabled
    && $hasCaptionSource
    && (!$isSimpleReservedPreset || !$showSettingsMenuControl);
$title = Arr::get($settings, 'title', '');
$description = Arr::get($settings, 'description', '');
$posterSrc = Arr::get($settings, 'posterSrc', '');
$showPoster = Arr::get($settings, 'show_poster', true);
$showTitleOverlay = Arr::get($settings, 'show_title_overlay', true);
$hasHeader = $showTitleOverlay && $title;
$posterClass = !$showPoster ? ' fp-audio-no-poster' : '';
$headerClass = !$hasHeader ? ' fp-audio-no-header' : '';

// Pro layouts pass these; for solo players or Free-only rendering default to safe values.
$isPlaylist = isset($isPlaylist) ? (bool) $isPlaylist : false;
$showPlaylistNavButtons = isset($showPlaylistNavButtons) ? (bool) $showPlaylistNavButtons : true;
$showPlaylistMenuToggle = isset($showPlaylistMenuToggle) ? (bool) $showPlaylistMenuToggle : true;
?>

<?php
// Helper: Render poster block
$renderPoster = function () use ($showPoster, $posterSrc, $title) {
    if (!$showPoster) return;
    if ($posterSrc): ?>
        <div class="fp-audio-poster" style="background-image: url('<?php echo esc_url($posterSrc); ?>');">
            <img src="<?php echo esc_url($posterSrc); ?>" alt="<?php echo esc_attr($title); ?>" width="100" height="100" />
        </div>
    <?php else: ?>
        <div class="fp-audio-poster fp-audio-poster-default">
            <span class="fp-audio-icon" aria-hidden="true">🎵</span>
            <span class="fp-sr-only"><?php echo esc_html__('Audio', 'fluent-player'); ?></span>
        </div>
    <?php endif;
};

// Helper: Render title/description block
$renderHeader = function () use ($showTitleOverlay, $title, $description) {
    if (!$showTitleOverlay) return;
    if (!$title && !$description) return;
    ?>
    <div class="fp-audio-header">
        <?php if ($title): ?>
            <h3 class="fp-audio-title"><?php echo esc_html($title); ?></h3>
        <?php endif; ?>
        <?php if ($description): ?>
            <p class="fp-audio-subtitle"><?php echo esc_html($description); ?></p>
        <?php endif; ?>
    </div>
    <?php
};

// Helper: Render secondary controls (speed, chapters, captions, settings, playlist-toggle)
$renderSecondaryControls = function () use ($controls, $settings, $hasChapters, $showSettingsMenuControl, $useSettingsForSpeedControls, $showStandaloneCaptionShortcut, $showStandaloneCaptionMenu, $isPlaylist, $showPlaylistMenuToggle) {
    ?>
    <div class="fp-audio-secondary-controls">
        <?php if (Arr::get($controls, 'playback_speed', true) && !$useSettingsForSpeedControls): ?>
            <?php include __DIR__ . '/controls/speed-menu.php'; ?>
        <?php endif; ?>

        <?php if ($hasChapters): ?>
            <?php include __DIR__ . '/controls/chapters-menu.php'; ?>
        <?php endif; ?>

        <?php if ($showStandaloneCaptionShortcut): ?>
            <?php include __DIR__ . '/controls/caption-toggle-button.php'; ?>
        <?php endif; ?>

        <?php if ($showStandaloneCaptionMenu): ?>
            <?php include __DIR__ . '/controls/caption-button.php'; ?>
        <?php endif; ?>

        <?php if ($showSettingsMenuControl): ?>
            <?php include __DIR__ . '/controls/settings-menu.php'; ?>
        <?php endif; ?>

        <?php if ($isPlaylist && $showPlaylistMenuToggle): ?>
            <?php include __DIR__ . '/controls/playlist-toggle.php'; ?>
        <?php endif; ?>
    </div>
    <?php
};
?>

<?php if ($skin === 'classic'): ?>
<!-- Classic Audio Skin: Progress bar on separate row, controls below -->
<media-controls class="fp-media-controls-bottom fp-skin-audio fp-skin-audio-classic">
    <media-controls-group class="fp-controls-group">
        <div class="fp-audio-layout<?php echo esc_attr($posterClass . $headerClass); ?>">
            <?php $renderPoster(); ?>
            <div class="fp-audio-content">
                <?php $renderHeader(); ?>

                <?php if (Arr::get($controls, 'progress_bar', true)): ?>
                    <div class="fp-audio-progress-row">
                        <?php include __DIR__ . '/controls/time-slider.php'; ?>
                    </div>
                <?php endif; ?>

                <div class="fp-audio-main-controls">
                    <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php include __DIR__ . '/controls/prev-track.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'backward', true)): ?>
                        <?php include __DIR__ . '/controls/seek-backward.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'play', true)): ?>
                        <?php include __DIR__ . '/controls/play-button.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'forward', true)): ?>
                        <?php include __DIR__ . '/controls/seek-forward.php'; ?>
                    <?php endif; ?>

                    <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php include __DIR__ . '/controls/next-track.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'volume', true)): ?>
                        <?php include __DIR__ . '/controls/volume-controls.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'current_time', true)): ?>
                        <?php include __DIR__ . '/controls/time-display.php'; ?>
                    <?php endif; ?>

                    <div class="fp-media-controls-spacer"></div>

                    <?php $renderSecondaryControls(); ?>
                </div>
            </div>
        </div>
    </media-controls-group>
</media-controls>

<?php elseif ($skin === 'modern'): ?>
<!-- Modern Audio Skin: Single row with all controls inline -->
<media-controls class="fp-media-controls-bottom fp-skin-audio fp-skin-audio-modern">
    <media-controls-group class="fp-controls-group">
        <div class="fp-audio-layout<?php echo esc_attr($posterClass . $headerClass); ?>">
            <?php $renderPoster(); ?>
            <div class="fp-audio-content">
                <?php $renderHeader(); ?>

                <div class="fp-audio-main-controls">
                    <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php include __DIR__ . '/controls/prev-track.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'backward', true)): ?>
                        <?php include __DIR__ . '/controls/seek-backward.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'play', true)): ?>
                        <?php include __DIR__ . '/controls/play-button.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'forward', true)): ?>
                        <?php include __DIR__ . '/controls/seek-forward.php'; ?>
                    <?php endif; ?>

                    <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php include __DIR__ . '/controls/next-track.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'current_time', true)): ?>
                        <?php include __DIR__ . '/controls/time-display.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'progress_bar', true)): ?>
                        <?php include __DIR__ . '/controls/time-slider.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'volume', true)): ?>
                        <div class="fp-audio-volume-group">
                            <?php include __DIR__ . '/controls/volume-controls.php'; ?>
                        </div>
                    <?php endif; ?>

                    <?php $renderSecondaryControls(); ?>
                </div>
            </div>
        </div>
    </media-controls-group>
</media-controls>

<?php elseif ($skin === 'simple'): ?>
<!-- Simple Audio Skin: Compact single row -->
<media-controls class="fp-media-controls-bottom fp-skin-audio fp-skin-audio-simple">
    <media-controls-group class="fp-controls-group">
        <div class="fp-audio-layout<?php echo esc_attr($posterClass . $headerClass); ?>">
            <?php $renderPoster(); ?>
            <div class="fp-audio-content">
                <?php $renderHeader(); ?>

                <div class="fp-audio-main-controls">
                    <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php include __DIR__ . '/controls/prev-track.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'play', true)): ?>
                        <?php include __DIR__ . '/controls/play-button.php'; ?>
                    <?php endif; ?>

                    <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php include __DIR__ . '/controls/next-track.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'volume', true)): ?>
                        <div class="fp-audio-volume-group">
                            <?php include __DIR__ . '/controls/volume-controls.php'; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'progress_bar', true)): ?>
                        <?php include __DIR__ . '/controls/time-slider.php'; ?>
                    <?php endif; ?>

                    <?php if ($showStandaloneCaptionShortcut): ?>
                        <?php include __DIR__ . '/controls/caption-toggle-button.php'; ?>
                    <?php endif; ?>

                    <?php if ($showStandaloneCaptionMenu): ?>
                        <?php include __DIR__ . '/controls/caption-button.php'; ?>
                    <?php endif; ?>

                    <?php if ($hasChapters): ?>
                        <?php include __DIR__ . '/controls/chapters-menu.php'; ?>
                    <?php endif; ?>

                    <?php if ($isPlaylist && $showPlaylistMenuToggle): ?>
                        <?php include __DIR__ . '/controls/playlist-toggle.php'; ?>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </media-controls-group>
</media-controls>

<?php elseif ($skin === 'standard'): ?>
<!-- Standard Audio Skin: Two-row layout -->
<media-controls class="fp-media-controls-bottom fp-skin-audio fp-skin-audio-standard">
    <media-controls-group class="fp-controls-group">
        <div class="fp-audio-layout<?php echo esc_attr($posterClass . $headerClass); ?>">
            <?php $renderPoster(); ?>
            <div class="fp-audio-content">
                <?php $renderHeader(); ?>

                <div class="fp-audio-controls-bar">
                    <div class="fp-standard-progress-row">
                        <?php if (Arr::get($controls, 'current_time', true)): ?>
                            <?php include __DIR__ . '/controls/time-current.php'; ?>
                        <?php endif; ?>

                        <?php if (Arr::get($controls, 'progress_bar', true)): ?>
                            <?php include __DIR__ . '/controls/time-slider.php'; ?>
                        <?php endif; ?>

                        <?php if (Arr::get($controls, 'current_time', true)): ?>
                            <?php include __DIR__ . '/controls/time-duration.php'; ?>
                        <?php endif; ?>
                    </div>

                    <div class="fp-standard-controls-row">
                    <div class="fp-standard-left-controls">
                        <?php if (Arr::get($controls, 'volume', true)): ?>
                            <?php include __DIR__ . '/controls/volume-controls.php'; ?>
                        <?php endif; ?>
                    </div>

                    <div class="fp-standard-center-controls">
                        <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                            <?php include __DIR__ . '/controls/prev-track.php'; ?>
                        <?php endif; ?>

                        <?php if (Arr::get($controls, 'backward', true)): ?>
                            <?php include __DIR__ . '/controls/seek-backward.php'; ?>
                        <?php endif; ?>

                        <?php if (Arr::get($controls, 'play', true)): ?>
                            <?php include __DIR__ . '/controls/play-button.php'; ?>
                        <?php endif; ?>

                        <?php if (Arr::get($controls, 'forward', true)): ?>
                            <?php include __DIR__ . '/controls/seek-forward.php'; ?>
                        <?php endif; ?>

                        <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                            <?php include __DIR__ . '/controls/next-track.php'; ?>
                        <?php endif; ?>
                    </div>

                    <div class="fp-standard-right-controls">
                        <?php if (Arr::get($controls, 'playback_speed', true) && !$useSettingsForSpeedControls): ?>
                            <?php include __DIR__ . '/controls/speed-menu.php'; ?>
                        <?php endif; ?>

                        <?php if ($hasChapters): ?>
                            <?php include __DIR__ . '/controls/chapters-menu.php'; ?>
                        <?php endif; ?>

                        <?php if ($showStandaloneCaptionShortcut): ?>
                            <?php include __DIR__ . '/controls/caption-toggle-button.php'; ?>
                        <?php endif; ?>

                        <?php if ($showStandaloneCaptionMenu): ?>
                            <?php include __DIR__ . '/controls/caption-button.php'; ?>
                        <?php endif; ?>

                        <?php if ($showSettingsMenuControl): ?>
                            <?php include __DIR__ . '/controls/settings-menu.php'; ?>
                        <?php endif; ?>

                        <?php if ($isPlaylist && $showPlaylistMenuToggle): ?>
                            <?php include __DIR__ . '/controls/playlist-toggle.php'; ?>
                        <?php endif; ?>

                    </div>
                </div>
                </div>
            </div>
        </div>
    </media-controls-group>
</media-controls>

<?php elseif ($skin === 'floating'): ?>
<!-- Floating Audio Skin: Rounded bar -->
<media-controls class="fp-media-controls-bottom fp-skin-audio fp-skin-audio-floating">
    <media-controls-group class="fp-controls-group">
        <div class="fp-audio-layout<?php echo esc_attr($posterClass . $headerClass); ?>">
            <?php $renderPoster(); ?>
            <div class="fp-audio-content">
                <?php $renderHeader(); ?>

                <div class="fp-audio-main-controls fp-floating-bar">
                    <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php include __DIR__ . '/controls/prev-track.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'backward', true)): ?>
                        <?php include __DIR__ . '/controls/seek-backward.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'play', true)): ?>
                        <?php include __DIR__ . '/controls/play-button.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'forward', true)): ?>
                        <?php include __DIR__ . '/controls/seek-forward.php'; ?>
                    <?php endif; ?>

                    <?php if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php include __DIR__ . '/controls/next-track.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'current_time', true)): ?>
                        <?php include __DIR__ . '/controls/time-display.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'progress_bar', true)): ?>
                        <?php include __DIR__ . '/controls/time-slider.php'; ?>
                    <?php endif; ?>

                    <?php if (Arr::get($controls, 'volume', true)): ?>
                        <div class="fp-audio-volume-group">
                            <?php include __DIR__ . '/controls/volume-controls.php'; ?>
                        </div>
                    <?php endif; ?>

                    <?php $renderSecondaryControls(); ?>
                </div>
            </div>
        </div>
    </media-controls-group>
</media-controls>

<?php elseif ($skin === 'minimal'): ?>
<!-- Minimal Audio Skin: Centered play button only -->
<media-controls class="fp-media-controls-bottom fp-skin-audio fp-skin-audio-minimal">
    <media-controls-group class="fp-controls-group">
        <div class="fp-audio-layout<?php echo esc_attr($posterClass . $headerClass); ?>">
            <?php $renderPoster(); ?>
            <div class="fp-audio-content">
                <?php $renderHeader(); ?>

                <div class="fp-audio-main-controls fp-audio-minimal-controls">
                    <?php include __DIR__ . '/controls/play-button.php'; ?>
                </div>
            </div>
        </div>
    </media-controls-group>
</media-controls>

<?php endif; ?>
