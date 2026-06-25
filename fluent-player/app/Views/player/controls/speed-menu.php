<?php
/**
 * Speed Menu
 */
if (!defined('ABSPATH')) exit;

?>
<media-menu>
    <media-tooltip>
        <media-tooltip-trigger>
            <media-menu-button class="fp-media-button" aria-label="<?php echo esc_attr__('Speed', 'fluent-player'); ?>">
                <media-icon type="odometer"></media-icon>
            </media-menu-button>
        </media-tooltip-trigger>
        <media-tooltip-content class="fp-media-tooltip" placement="top">
            <span><?php echo esc_html__('Speed', 'fluent-player'); ?></span>
        </media-tooltip-content>
    </media-tooltip>
    <media-menu-items placement="top end">
        <media-speed-radio-group class="fp-media-radio-group">
            <template>
                <media-radio>
                    <div class="fp-media-radio-check"></div>
                    <span class="fp-media-radio-label" data-part="label"></span>
                </media-radio>
            </template>
        </media-speed-radio-group>
    </media-menu-items>
</media-menu>