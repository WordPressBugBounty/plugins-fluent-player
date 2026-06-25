<?php
/**
 * Play Button
 */
if (!defined('ABSPATH')) exit;

?>
<media-tooltip>
    <media-tooltip-trigger>
        <media-play-button class="fp-media-button" aria-label="<?php echo esc_attr__('Play', 'fluent-player'); ?>">
            <media-icon class="fp-media-play-icon" type="play"></media-icon>
            <media-icon class="fp-media-pause-icon" type="pause"></media-icon>
        </media-play-button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-play-tooltip-text"><?php echo esc_html__('Play', 'fluent-player'); ?></span>
        <span class="fp-media-pause-tooltip-text"><?php echo esc_html__('Pause', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>