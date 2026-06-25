<?php
/**
 * Playlist Toggle Button
 * Shows/hides the playlist sidebar
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

?>

<media-tooltip>
    <media-tooltip-trigger>
        <button type="button" class="fp-media-button fp-playlist-toggle" data-playlist-toggle aria-label="<?php echo esc_attr__('Toggle Playlist', 'fluent-player'); ?>">
            <media-icon type="playlist"></media-icon>
        </button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-play-tooltip-text"><?php echo esc_html__('Toggle Playlist', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>

