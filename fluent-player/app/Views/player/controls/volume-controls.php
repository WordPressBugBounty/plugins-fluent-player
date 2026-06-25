<?php
/**
 * Volume Controls
 */
if (!defined('ABSPATH')) exit;

?>
<media-tooltip>
    <media-tooltip-trigger>
        <media-mute-button class="fp-media-button" aria-label="<?php echo esc_attr__('Mute', 'fluent-player'); ?>">
            <media-icon class="fp-media-mute-icon" type="mute"></media-icon>
            <media-icon class="fp-media-volume-low-icon" type="volume-low"></media-icon>
            <media-icon class="fp-media-volume-high-icon" type="volume-high"></media-icon>
        </media-mute-button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-mute-tooltip-text"><?php echo esc_html__('Unmute', 'fluent-player'); ?></span>
        <span class="fp-media-unmute-tooltip-text"><?php echo esc_html__('Mute', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>

<media-volume-slider class="fp-media-slider" aria-label="<?php echo esc_attr__('Volume', 'fluent-player'); ?>">
    <div class="fp-media-slider-track"></div>
    <div class="fp-media-slider-track-fill fp-media-slider-track"></div>
    <div class="fp-media-slider-thumb"></div>
    <media-slider-preview no-clamp>
        <media-slider-value></media-slider-value>
    </media-slider-preview>
</media-volume-slider>