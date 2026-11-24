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
$hasLogoLink = '' !== esc_url($logoLink);
$logoPosition = Arr::get($default_settings, 'logoPosition', 'position-top-right');
$logoWidth = Arr::get($default_settings, 'logoWidth', 24);

// If no logo, don't display anything
if (empty($logoUrl)) {
    return;
}
?>

<?php // Muted Preview starts the logo hidden and its script reveals it; otherwise CSS decides, so audio can style it as a watermark. ?>
<div class="fp-media-custom-overlay fp-media-logo-overlay <?php echo esc_attr($logoPosition); ?> active"<?php echo !empty($logoPreviewHidden) ? ' style="display: none !important;"' : ''; ?>>
    <div class="fp-media-custom-overlay-block fp-media-logo-block" style="background-color: transparent;">
        <a
            class="fp-media-custom-overlay-link fp-media-logo-link"
            href="<?php echo esc_url($hasLogoLink ? $logoLink : '#'); ?>"
            <?php if ($hasLogoLink) : ?>target="_blank" rel="noopener noreferrer"<?php else : ?>onclick="return false;"<?php endif; ?>
        >
            <img
                src="<?php echo esc_url($logoUrl); ?>"
                alt="<?php echo esc_attr(get_bloginfo('name')); ?>"
                class="fp-media-logo-image"
                style="--fp-logo-width: <?php echo esc_attr($logoWidth); ?>px; max-width: <?php echo esc_attr($logoWidth); ?>px;"
            />
        </a>
    </div>
</div>
