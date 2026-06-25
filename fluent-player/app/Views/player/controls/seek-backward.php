<?php
/**
 * Seek Backward Button
 */
if (!defined('ABSPATH')) exit;

?>
<media-tooltip>
    <media-tooltip-trigger>
        <media-seek-button class="fp-media-button" seconds="-10" aria-label="<?php echo esc_attr__('Backward 10 seconds', 'fluent-player'); ?>">
            <media-icon class="fp-seek-backward-10" type="seek-backward-10"></media-icon>
        </media-seek-button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-play-tooltip-text"><?php echo esc_html__('Backward 10s', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>