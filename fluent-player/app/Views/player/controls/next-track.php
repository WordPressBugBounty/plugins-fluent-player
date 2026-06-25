<?php
/**
 * Next Track Button (playlist navigation)
 *
 * Rendered only in playlist context. Wired to FluentPlaylist.nextMedia()
 * via the .fp-next-btn class selector in resources/js/FluentPlaylist.js.
 */
if (!defined('ABSPATH')) exit;

?>
<media-tooltip>
    <media-tooltip-trigger>
        <button type="button" class="fp-media-button fp-next-btn fp-next-track" aria-label="<?php echo esc_attr__('Next track', 'fluent-player'); ?>">
            <media-icon type="next"></media-icon>
        </button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-play-tooltip-text"><?php echo esc_html__('Next track', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>
