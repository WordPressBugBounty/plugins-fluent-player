<?php
/**
 * Custom Overlays
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Helpers\Helper;

if (!defined('ABSPATH')) exit;

if (!Helper::hasPro()) {
    return;
}

?>
<?php if (Arr::get($settings, 'overlays')): ?>
    <?php foreach (Arr::get($settings, 'overlays') as $index => $overlay): ?>
    <?php
    $overlay_link = Arr::get($overlay, 'link', '');
    $overlay_link = is_string($overlay_link) ? trim($overlay_link) : '';
    $overlay_text = Arr::get($overlay, 'text', '');
    $has_html = $overlay_text !== strip_tags($overlay_text);
    $has_link = $overlay_link !== '';
    ?>
    <?php
    $overlay_bg = Arr::get($overlay, 'backgroundColor', '');
    $overlay_text_color = Arr::get($overlay, 'textColor', '');
    $overlay_style = ($overlay_bg !== '' ? 'background-color: ' . esc_attr($overlay_bg) . ';' : '') . ($overlay_text_color !== '' ? ' color: ' . esc_attr($overlay_text_color) . ';' : '');
    ?>
    <?php $is_dynamic = !empty(Arr::get($overlay, 'dynamic_position')); ?>
    <div class="fp-media-custom-overlay <?php echo $is_dynamic ? 'position-dynamic' : esc_attr(Arr::get($overlay, 'position')); ?>">
        <div class="fp-media-custom-overlay-block" style="<?php echo esc_attr($overlay_style); ?>">
            <?php if ($has_link && !$has_html) : ?>
                <a class="fp-media-custom-overlay-link" href="<?php echo esc_url($overlay_link); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html($overlay_text); ?>
                </a>
            <?php elseif ($has_html && $has_link) : ?>
                <span class="fp-media-custom-overlay-text"><?php echo Helper::escSmartcodeHtml($overlay_text); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper::escSmartcodeHtml runs wp_kses_post on the value. ?></span>
                <a class="fp-media-custom-overlay-link fp-media-custom-overlay-stretch-link" href="<?php echo esc_url($overlay_link); ?>" target="_blank" rel="noopener noreferrer" aria-hidden="true"></a>
            <?php else : ?>
                <span class="fp-media-custom-overlay-text"><?php echo Helper::escSmartcodeHtml($overlay_text); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper::escSmartcodeHtml runs wp_kses_post on the value. ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>