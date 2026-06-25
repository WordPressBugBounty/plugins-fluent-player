<?php

/**
 * Time Slider
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

/** @var array $settings */

?>
<media-time-slider class="fp-media-slider" aria-label="<?php echo esc_attr__('Seek', 'fluent-player'); ?>">
    <media-slider-chapters>
        <template>
            <div class="fp-media-slider-chapter">
                <div class="fp-media-slider-track"></div>
                <div class="fp-media-slider-track-fill fp-media-slider-track"></div>
                <div class="fp-media-slider-progress fp-media-slider-track"></div>
            </div>
        </template>
    </media-slider-chapters>
    <div class="fp-media-slider-thumb"></div>
    <media-slider-preview>
        <?php
        $thumbnails = \FluentPlayer\Framework\Support\Arr::get($settings, 'thumbnails', '');
        if ($thumbnails):
        ?>
            <media-slider-thumbnail src="<?php echo esc_url($thumbnails); ?>"></media-slider-thumbnail>
        <?php
        endif;
        ?>
        <div data-part="chapter-title"></div>
        <media-slider-value></media-slider-value>
    </media-slider-preview>

</media-time-slider>
