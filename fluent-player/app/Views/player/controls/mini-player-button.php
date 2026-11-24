<?php
/**
 * Audio Mini Player Button
 */
if (!defined('ABSPATH')) exit;

?>
<media-tooltip>
    <media-tooltip-trigger>
        <button type="button" class="fp-media-button fp-mini-player-toggle" aria-label="<?php echo esc_attr__('Show miniplayer when scrolling', 'fluent-player'); ?>" aria-pressed="false"<?php echo !empty($miniPlayerButtonHidden) ? ' hidden' : ''; ?>>
            <media-icon type="picture-in-picture" aria-hidden="true"></media-icon>
        </button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span><?php echo esc_html__('Show miniplayer when scrolling', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>
