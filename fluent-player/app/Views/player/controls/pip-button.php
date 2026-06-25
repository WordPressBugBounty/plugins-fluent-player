<?php
/**
 * PIP Button
 */
if (!defined('ABSPATH')) exit;

?>
<media-tooltip>
    <media-tooltip-trigger>
        <media-pip-button class="fp-media-button" aria-label="<?php echo esc_attr__('Picture in Picture', 'fluent-player'); ?>">
            <media-icon class="fp-media-pip-enter-icon" type="picture-in-picture"></media-icon>
            <media-icon class="fp-media-pip-exit-icon" type="picture-in-picture-exit"></media-icon>
        </media-pip-button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-pip-enter-tooltip-text"><?php echo esc_html__('Enter PiP', 'fluent-player'); ?></span>
        <span class="fp-media-pip-exit-tooltip-text"><?php echo esc_html__('Exit PiP', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>