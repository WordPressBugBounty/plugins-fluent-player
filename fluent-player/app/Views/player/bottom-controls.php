<?php

/**
 * Bottom Controls
 * @var string $skin
 * @var array $controls
 * @var array $settings
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

$hasChapters = !empty(Arr::get($settings, 'chapters', []));
$videoSrc = Arr::get($settings, 'src', '');
$provider = Arr::get($settings, 'provider', '');
$hasHls = ($provider === 'bunny' || $provider === 'mux' || $provider === 'mux_stream' || strpos($videoSrc, '.m3u8') !== false);
$subtitles = Arr::get($settings, 'subtitles', []);
$hasSubtitles = !empty($subtitles);
$subtitleCount = is_array($subtitles) ? count($subtitles) : 0;
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
$showQualityMenu = Arr::get($controls, 'quality', true) && $hasHls && !$showSettingsMenu;

// Pro layouts pass these; for solo players or Free-only rendering default to safe values.
$isPlaylist = isset($isPlaylist) ? (bool) $isPlaylist : false;
$showPlaylistNavButtons = isset($showPlaylistNavButtons) ? (bool) $showPlaylistNavButtons : true;
$showPlaylistMenuToggle = isset($showPlaylistMenuToggle) ? (bool) $showPlaylistMenuToggle : true;
?>

<?php
if ($skin === 'classic'): ?>
    <!-- Classic Skin -->
    <media-controls class="fp-media-controls-bottom fp-skin-classic">
        <div class="fp-media-controls-spacer"></div>

        <?php
        if (Arr::get($controls, 'progress_bar', true)): ?>
            <media-controls-group class="fp-controls-group">
            <?php
            include __DIR__ . '/controls/time-slider.php'; ?>
            </media-controls-group>
        <?php
        endif; ?>

        <media-controls-group class="fp-controls-group">
            <?php
            if ($isPlaylist && $showPlaylistNavButtons): ?>
                <?php
                include __DIR__ . '/controls/prev-track.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'backward', true)): ?>
                <?php
                include __DIR__ . '/controls/seek-backward.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'play', true)): ?>
                <?php
                include __DIR__ . '/controls/play-button.php'; ?>
            <?php
            endif; ?>

            <?php if (Arr::get($settings, 'streamType') === 'live'): ?>
                <div class="fp-live-indicator">
                    <span class="fp-live-indicator-dot"></span>
                    <?php echo esc_html__('LIVE', 'fluent-player'); ?>
                </div>
            <?php endif; ?>

            <?php
            if (Arr::get($controls, 'forward', true)): ?>
                <?php
                include __DIR__ . '/controls/seek-forward.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($isPlaylist && $showPlaylistNavButtons): ?>
                <?php
                include __DIR__ . '/controls/next-track.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'volume', true)): ?>
                <?php
                include __DIR__ . '/controls/volume-controls.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'current_time', true)): ?>
                <?php
                include __DIR__ . '/controls/time-display.php'; ?>
            <?php
            endif; ?>

            <div class="fp-media-controls-spacer"></div>

            <?php
            if ($showStandaloneCaptionShortcut): ?>
                <?php
                include __DIR__ . '/controls/caption-toggle-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showStandaloneCaptionMenu): ?>
                <?php
                include __DIR__ . '/controls/caption-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'playback_speed', true) && !$useSettingsForSpeedControls): ?>
                <?php
                include __DIR__ . '/controls/speed-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showQualityMenu): ?>
                <?php
                include __DIR__ . '/controls/quality-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showSettingsMenuControl): ?>
                <?php
                include __DIR__ . '/controls/settings-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'chapters', true) && $hasChapters): ?>
                <?php
                include __DIR__ . '/controls/chapters-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'pip', true)): ?>
                <?php
                include __DIR__ . '/controls/pip-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'fullscreen', true)): ?>
                <?php
                include __DIR__ . '/controls/fullscreen-button.php'; ?>
            <?php
            endif; ?>

            <?php
            // Show playlist toggle button if in playlist context
            if ($isPlaylist && $showPlaylistMenuToggle): ?>
                <?php
                include __DIR__ . '/controls/playlist-toggle.php'; ?>
            <?php
            endif; ?>
        </media-controls-group>
    </media-controls>

<?php
elseif ($skin === 'modern'): ?>
    <!-- Modern Skin -->
    <media-controls class="fp-media-controls-bottom fp-skin-modern">
        <div class="fp-media-controls-spacer"></div>

        <media-controls-group class="fp-controls-group">
            <?php
            if ($isPlaylist && $showPlaylistNavButtons): ?>
                <?php
                include __DIR__ . '/controls/prev-track.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'backward', true)): ?>
                <?php
                include __DIR__ . '/controls/seek-backward.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'play', true)): ?>
                <?php
                include __DIR__ . '/controls/play-button.php'; ?>
            <?php
            endif; ?>

            <?php if (Arr::get($settings, 'streamType') === 'live'): ?>
                <div class="fp-live-indicator">
                    <span class="fp-live-indicator-dot"></span>
                    <?php echo esc_html__('LIVE', 'fluent-player'); ?>
                </div>
            <?php endif; ?>

            <?php
            if (Arr::get($controls, 'forward', true)): ?>
                <?php
                include __DIR__ . '/controls/seek-forward.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($isPlaylist && $showPlaylistNavButtons): ?>
                <?php
                include __DIR__ . '/controls/next-track.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'volume', true)): ?>
                <?php
                include __DIR__ . '/controls/volume-controls.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'current_time', true)): ?>
                <?php
                include __DIR__ . '/controls/time-display.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'progress_bar', true)): ?>
                <?php
                include __DIR__ . '/controls/time-slider.php'; ?>
            <?php
            endif; ?>


            <?php
            if ($showStandaloneCaptionShortcut): ?>
                <?php
                include __DIR__ . '/controls/caption-toggle-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showStandaloneCaptionMenu): ?>
                <?php
                include __DIR__ . '/controls/caption-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'playback_speed', true) && !$useSettingsForSpeedControls): ?>
                <?php
                include __DIR__ . '/controls/speed-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showQualityMenu): ?>
                <?php
                include __DIR__ . '/controls/quality-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showSettingsMenuControl): ?>
                <?php
                include __DIR__ . '/controls/settings-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'chapters', true) && $hasChapters): ?>
                <?php
                include __DIR__ . '/controls/chapters-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'pip', true)): ?>
                <?php
                include __DIR__ . '/controls/pip-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'fullscreen', true)): ?>
                <?php
                include __DIR__ . '/controls/fullscreen-button.php'; ?>
            <?php
            endif; ?>

            <?php
            // Show playlist toggle button if in playlist context
            if ($isPlaylist && $showPlaylistMenuToggle): ?>
                <?php
                include __DIR__ . '/controls/playlist-toggle.php'; ?>
            <?php
            endif; ?>

            <?php
            // Show info toggle button if in grid modal context
            if (isset($isGridModal) && $isGridModal): ?>
                <?php
                include __DIR__ . '/controls/info-toggle.php'; ?>
            <?php
            endif; ?>
        </media-controls-group>
    </media-controls>

<?php
elseif ($skin === 'simple'): ?>
    <!-- Simple Skin -->
    <media-controls class="fp-media-controls-bottom fp-skin-simple">
        <div class="fp-media-controls-spacer"></div>

        <media-controls-group class="fp-controls-group">
            <?php
            if ($isPlaylist && $showPlaylistNavButtons): ?>
                <?php
                include __DIR__ . '/controls/prev-track.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'play', true)): ?>
                <?php
                include __DIR__ . '/controls/play-button.php'; ?>
            <?php
            endif; ?>

            <?php if (Arr::get($settings, 'streamType') === 'live'): ?>
                <div class="fp-live-indicator">
                    <span class="fp-live-indicator-dot"></span>
                    <?php echo esc_html__('LIVE', 'fluent-player'); ?>
                </div>
            <?php endif; ?>

            <?php
            if ($isPlaylist && $showPlaylistNavButtons): ?>
                <?php
                include __DIR__ . '/controls/next-track.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'volume', true)): ?>
                <?php
                include __DIR__ . '/controls/volume-controls.php'; ?>
            <?php
            endif; ?>

            <?php
            include __DIR__ . '/controls/time-slider.php'; ?>

            <?php
            if ($showStandaloneCaptionShortcut): ?>
                <?php
                include __DIR__ . '/controls/caption-toggle-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showStandaloneCaptionMenu): ?>
                <?php
                include __DIR__ . '/controls/caption-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showSettingsMenuControl): ?>
                <?php
                include __DIR__ . '/controls/settings-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'chapters', true) && $hasChapters): ?>
                <?php
                include __DIR__ . '/controls/chapters-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'pip', true)): ?>
                <?php
                include __DIR__ . '/controls/pip-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'fullscreen', true)): ?>
                <?php
                include __DIR__ . '/controls/fullscreen-button.php'; ?>
            <?php
            endif; ?>

            <?php
            // Show playlist toggle button if in playlist context
            if ($isPlaylist && $showPlaylistMenuToggle): ?>
                <?php
                include __DIR__ . '/controls/playlist-toggle.php'; ?>
            <?php
            endif; ?>

            <?php
            // Show info toggle button if in grid modal context
            if (isset($isGridModal) && $isGridModal): ?>
                <?php
                include __DIR__ . '/controls/info-toggle.php'; ?>
            <?php
            endif; ?>
        </media-controls-group>
    </media-controls>

<?php
elseif ($skin === 'floating'): ?>
    <!-- Floating Skin - Inline Controls -->
    <media-controls class="fp-media-controls-bottom fp-skin-floating">
        <div class="fp-media-controls-spacer"></div>

        <media-controls-group class="fp-controls-group fp-floating-bar">
            <?php
            if ($isPlaylist && $showPlaylistNavButtons): ?>
                <?php
                include __DIR__ . '/controls/prev-track.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'backward', true)): ?>
                <?php
                include __DIR__ . '/controls/seek-backward.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'play', true)): ?>
                <?php
                include __DIR__ . '/controls/play-button.php'; ?>
            <?php
            endif; ?>

            <?php if (Arr::get($settings, 'streamType') === 'live'): ?>
                <div class="fp-live-indicator">
                    <span class="fp-live-indicator-dot"></span>
                    <?php echo esc_html__('LIVE', 'fluent-player'); ?>
                </div>
            <?php endif; ?>

            <?php
            if (Arr::get($controls, 'forward', true)): ?>
                <?php
                include __DIR__ . '/controls/seek-forward.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($isPlaylist && $showPlaylistNavButtons): ?>
                <?php
                include __DIR__ . '/controls/next-track.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'current_time', true)): ?>
                <?php
                include __DIR__ . '/controls/time-display.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'progress_bar', true)): ?>
                <?php
                include __DIR__ . '/controls/time-slider.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'volume', true)): ?>
                <?php
                include __DIR__ . '/controls/volume-controls.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'playback_speed', true) && !$useSettingsForSpeedControls): ?>
                <?php
                include __DIR__ . '/controls/speed-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showQualityMenu): ?>
                <?php
                include __DIR__ . '/controls/quality-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showSettingsMenuControl): ?>
                <?php
                include __DIR__ . '/controls/settings-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showStandaloneCaptionShortcut): ?>
                <?php
                include __DIR__ . '/controls/caption-toggle-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if ($showStandaloneCaptionMenu): ?>
                <?php
                include __DIR__ . '/controls/caption-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'chapters', true) && $hasChapters): ?>
                <?php
                include __DIR__ . '/controls/chapters-menu.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'pip', true)): ?>
                <?php
                include __DIR__ . '/controls/pip-button.php'; ?>
            <?php
            endif; ?>

            <?php
            if (Arr::get($controls, 'fullscreen', true)): ?>
                <?php
                include __DIR__ . '/controls/fullscreen-button.php'; ?>
            <?php
            endif; ?>

            <?php
            // Show playlist toggle button if in playlist context
            if ($isPlaylist && $showPlaylistMenuToggle): ?>
                <?php
                include __DIR__ . '/controls/playlist-toggle.php'; ?>
            <?php
            endif; ?>

            <?php
            // Show info toggle button if in grid modal context
            if (isset($isGridModal) && $isGridModal): ?>
                <?php
                include __DIR__ . '/controls/info-toggle.php'; ?>
            <?php
            endif; ?>
        </media-controls-group>
    </media-controls>

<?php
elseif ($skin === 'standard'): ?>
    <!-- Standard Skin - Two-row layout with glass morphism -->
    <media-controls class="fp-media-controls-bottom fp-skin-standard">
        <div class="fp-media-controls-spacer"></div>

        <media-controls-group class="fp-controls-group">
            <!-- Progress Row -->
            <div class="fp-standard-progress-row">
                <?php
                if (Arr::get($controls, 'current_time', true)): ?>
                    <?php
                    include __DIR__ . '/controls/time-current.php'; ?>
                <?php
                endif; ?>

                <?php
                if (Arr::get($controls, 'progress_bar', true)): ?>
                    <?php
                    include __DIR__ . '/controls/time-slider.php'; ?>
                <?php
                endif; ?>

                <?php
                if (Arr::get($controls, 'current_time', true)): ?>
                    <?php
                    include __DIR__ . '/controls/time-duration.php'; ?>
                <?php
                endif; ?>
            </div>

            <!-- Controls Row -->
            <div class="fp-standard-controls-row">
                <!-- Left Controls (Volume) -->
                <div class="fp-standard-left-controls">
                    <?php
                    if (Arr::get($controls, 'volume', true)): ?>
                        <?php
                        include __DIR__ . '/controls/volume-controls.php'; ?>
                    <?php
                    endif; ?>
                </div>

                <!-- Center Controls (Playback) -->
                <div class="fp-standard-center-controls">
                    <?php if (Arr::get($settings, 'streamType') === 'live'): ?>
                        <div class="fp-live-indicator">
                            <span class="fp-live-indicator-dot"></span>
                            <?php echo esc_html__('LIVE', 'fluent-player'); ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php
                        include __DIR__ . '/controls/prev-track.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    if (Arr::get($controls, 'backward', true)): ?>
                        <?php
                        include __DIR__ . '/controls/seek-backward.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    if (Arr::get($controls, 'play', true)): ?>
                        <?php
                        include __DIR__ . '/controls/play-button.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    if (Arr::get($controls, 'forward', true)): ?>
                        <?php
                        include __DIR__ . '/controls/seek-forward.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    if ($isPlaylist && $showPlaylistNavButtons): ?>
                        <?php
                        include __DIR__ . '/controls/next-track.php'; ?>
                    <?php
                    endif; ?>
                </div>

                <!-- Right Controls (Utility) -->
                <div class="fp-standard-right-controls">
                    <?php
                        if ($showStandaloneCaptionShortcut): ?>
                            <?php
                            include __DIR__ . '/controls/caption-toggle-button.php'; ?>
                        <?php
                        endif; ?>

                        <?php
                        if ($showStandaloneCaptionMenu): ?>
                            <?php
                            include __DIR__ . '/controls/caption-button.php'; ?>
                        <?php
                        endif; ?>

                        <?php
                        if (Arr::get($controls, 'playback_speed', true) && !$useSettingsForSpeedControls): ?>
                            <?php
                            include __DIR__ . '/controls/speed-menu.php'; ?>
                        <?php
                    endif; ?>

                    <?php
                    if ($showQualityMenu): ?>
                        <?php
                        include __DIR__ . '/controls/quality-menu.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    if ($showSettingsMenuControl): ?>
                        <?php
                        include __DIR__ . '/controls/settings-menu.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    if (Arr::get($controls, 'chapters', true) && $hasChapters): ?>
                        <?php
                        include __DIR__ . '/controls/chapters-menu.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    if (Arr::get($controls, 'pip', true)): ?>
                        <?php
                        include __DIR__ . '/controls/pip-button.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    if (Arr::get($controls, 'fullscreen', true)): ?>
                        <?php
                        include __DIR__ . '/controls/fullscreen-button.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    // Show playlist toggle button if in playlist context
                    if ($isPlaylist && $showPlaylistMenuToggle): ?>
                        <?php
                        include __DIR__ . '/controls/playlist-toggle.php'; ?>
                    <?php
                    endif; ?>

                    <?php
                    // Show info toggle button if in grid modal context
                    if (isset($isGridModal) && $isGridModal): ?>
                        <?php
                        include __DIR__ . '/controls/info-toggle.php'; ?>
                    <?php
                    endif; ?>
                </div>
            </div>
        </media-controls-group>
    </media-controls>

<?php
elseif ($skin === 'minimal'): ?>
    <!-- Minimal Skin -->
    <media-controls class="fp-media-controls-bottom fp-skin-minimal">
        <div class="fp-media-controls-spacer"></div>
    </media-controls>
<?php
endif; ?>
