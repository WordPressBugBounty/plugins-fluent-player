<?php
/**
 * Chapters Menu
 */
if (!defined('ABSPATH')) exit;

?>
<media-menu>
    <media-tooltip>
        <media-tooltip-trigger>
            <media-menu-button class="fp-media-button" aria-label="<?php echo esc_attr__('Chapters', 'fluent-player'); ?>">
                <media-icon class="fp-media-chapter-icon" type="chapters"></media-icon>
            </media-menu-button>
        </media-tooltip-trigger>
        <media-tooltip-content class="fp-media-tooltip" placement="top">
            <span><?php echo esc_html__('Chapters', 'fluent-player'); ?></span>
        </media-tooltip-content>
    </media-tooltip>
    <media-menu-items placement="top end">
        <media-chapters-radio-group class="fp-media-radio-group">
            <template>
                <media-radio>
                    <div class="fp-media-radio-check"></div>
                    <span class="fp-media-radio-label" data-part="label"></span>
                </media-radio>
            </template>
        </media-chapters-radio-group>
    </media-menu-items>
</media-menu>