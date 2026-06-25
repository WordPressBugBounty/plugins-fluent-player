<?php
/**
 * Fullscreen Button
 */
if (!defined('ABSPATH')) exit;

?>
<media-tooltip>
    <media-tooltip-trigger>
        <media-fullscreen-button class="fp-media-button" aria-label="<?php echo esc_attr__('Fullscreen', 'fluent-player'); ?>">
            <media-icon class="fp-media-fs-enter-icon" type="fullscreen"></media-icon>
            <media-icon class="fp-media-fs-exit-icon" type="fullscreen-exit"></media-icon>
        </media-fullscreen-button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top end">
        <span class="fp-media-fs-enter-tooltip-text"><?php echo esc_html__('Enter Fullscreen', 'fluent-player'); ?></span>
        <span class="fp-media-fs-exit-tooltip-text"><?php echo esc_html__('Exit Fullscreen', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>