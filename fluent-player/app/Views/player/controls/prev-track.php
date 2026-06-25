<?php
/**
 * Previous Track Button (playlist navigation)
 *
 * Rendered only in playlist context. Wired to FluentPlaylist.prevMedia()
 * via the .fp-prev-btn class selector in resources/js/FluentPlaylist.js.
 */
if (!defined('ABSPATH')) exit;

?>
<media-tooltip>
    <media-tooltip-trigger>
        <button type="button" class="fp-media-button fp-prev-btn fp-prev-track" aria-label="<?php echo esc_attr__('Previous track', 'fluent-player'); ?>">
            <media-icon type="previous"></media-icon>
        </button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-play-tooltip-text"><?php echo esc_html__('Previous track', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>
