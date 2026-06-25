<?php
/**
 * Caption Toggle Button
 *
 * Single-click captions toggle for quick on/off access.
 */

if (!defined('ABSPATH')) exit;
?>
<media-tooltip>
    <media-tooltip-trigger>
        <media-caption-button class="fp-media-button" aria-label="<?php echo esc_attr__('Captions', 'fluent-player'); ?>">
            <media-icon class="fp-media-cc-on-icon" type="closed-captions-on"></media-icon>
            <media-icon class="fp-media-cc-off-icon" type="closed-captions"></media-icon>
        </media-caption-button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-cc-on-tooltip-text"><?php echo esc_html__('Closed-Captions Off', 'fluent-player'); ?></span>
        <span class="fp-media-cc-off-tooltip-text"><?php echo esc_html__('Closed-Captions On', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>
