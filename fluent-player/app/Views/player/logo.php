<?php
/**
 * Player Logo
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\Framework\Support\Arr;

if (!defined('ABSPATH')) exit;

// Get logo settings from default_settings
/** @var Array $default_settings */
$default_settings = $default_settings ?? [];
$logoUrl = Arr::get($default_settings, 'logoUrl', '');
$logoLink = Arr::get($default_settings, 'logoLink', '');
$logoPosition = Arr::get($default_settings, 'logoPosition', 'position-top-right');
$logoWidth = Arr::get($default_settings, 'logoWidth', 24);

// If no logo, don't display anything
if (empty($logoUrl)) {
    return;
}
?>

<div class="fp-media-custom-overlay fp-media-logo-overlay <?php echo esc_attr($logoPosition); ?> active" style="opacity: 1 !important; display: block !important;">
    <div class="fp-media-custom-overlay-block fp-media-logo-block" style="background-color: transparent;">
        <a
            class="fp-media-custom-overlay-link fp-media-logo-link"
            href="<?php echo esc_url($logoLink ? $logoLink : '#'); ?>"
            <?php echo $logoLink ? esc_attr('target="_blank" rel="noopener noreferrer"') : ''; ?>
            <?php echo !$logoLink ? esc_attr('onclick="return false;"') : ''; ?>
        >
            <img
                src="<?php echo esc_url($logoUrl); ?>"
                alt="<?php echo esc_attr__('Player Logo', 'fluent-player'); ?>"
                class="fp-media-logo-image"
                style="max-width: <?php echo esc_attr($logoWidth); ?>px;"
            />
        </a>
    </div>
</div>
