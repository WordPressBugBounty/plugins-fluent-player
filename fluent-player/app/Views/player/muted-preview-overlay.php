<?php
/**
 * Muted preview click-catcher.
 *
 * Server-rendered so it exists from first paint — the preview starts before the
 * JS bundle loads, and clicks in that window must land here (absorbed until
 * wired), never on the player underneath.
 *
 * The play icon mirrors the regular center play button (same branding vars and
 * dimensions) and follows its visibility: when the preset hides the center
 * controls, only the invisible click surface remains.
 */

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Helpers\Helper;

$previewOverlay = Helper::hasPro() ? Arr::get($settings ?? [], 'mutedPreviewConfig.overlay', []) : [];
$previewOverlayType = Arr::get($previewOverlay, 'type', '');
$previewOverlaySrc = Arr::get($previewOverlay, 'src', '');
$previewOverlayText = Arr::get($previewOverlay, 'text', '');
$previewOverlayWidth = max(5, min(100, (int) Arr::get($previewOverlay, 'width', 40)));
$previewOverlayOpacity = max(10, min(100, (int) Arr::get($previewOverlay, 'opacity', 100)));
$previewOverlayPosition = sanitize_html_class(Arr::get($previewOverlay, 'position', 'center'));
$hasPreviewOverlay = ($previewOverlayType === 'image' && $previewOverlaySrc)
    || ($previewOverlayType === 'text' && $previewOverlayText !== '');
$previewIconHidden = Arr::isTrue($behaviors ?? [], 'hide_center_controls');
?>
<button type="button" class="fp-muted-preview-overlay<?php echo $previewIconHidden ? ' fp-muted-preview-overlay--bare' : ''; ?>" aria-label="<?php echo esc_attr__('Play with sound', 'fluent-player'); ?>">
    <?php if (!$previewIconHidden): ?>
    <span class="fp-muted-preview-play" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M8 5.14v13.72c0 .8.87 1.3 1.56.88l11-6.86a1.04 1.04 0 0 0 0-1.76l-11-6.86A1.04 1.04 0 0 0 8 5.14Z" fill="currentColor"/>
        </svg>
    </span>
    <?php endif; ?>

    <?php if ($hasPreviewOverlay): ?>
    <span class="fp-mp-overlay fp-mp-pos-<?php echo esc_attr($previewOverlayPosition); ?>" style="width: <?php echo esc_attr($previewOverlayWidth); ?>%; opacity: <?php echo esc_attr($previewOverlayOpacity / 100); ?>;"<?php echo $previewOverlayType === 'image' ? ' aria-hidden="true"' : ''; ?>>
        <?php if ($previewOverlayType === 'image'): ?>
            <img src="<?php echo esc_url($previewOverlaySrc); ?>" alt="" />
        <?php else: ?>
            <span class="fp-mp-overlay-text"><?php echo Helper::escSmartcodeHtml($previewOverlayText); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper::escSmartcodeHtml runs wp_kses_post on the value. ?></span>
        <?php endif; ?>
    </span>
    <?php endif; ?>
</button>
