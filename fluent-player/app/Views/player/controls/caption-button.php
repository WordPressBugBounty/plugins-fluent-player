<?php
/**
 * Caption Button with Subtitle Support
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

$hasPro = \FluentPlayer\App\Helpers\Helper::hasPro();
$subtitles = Arr::get($settings, 'subtitles', []);
// Filter out subtitles without a URL (e.g. Mux auto-captions stored as metadata only)
$subtitlesWithUrl = array_filter($subtitles, function ($s) {
    return !empty($s['url'] ?? '');
});
$hasCustomSubtitles = !empty($subtitlesWithUrl) && $hasPro;

// HLS providers (Mux, Bunny) may embed captions in the manifest that hls.js
// discovers at runtime — show the CC menu even without manual subtitle entries.
$provider = Arr::get($settings, 'provider', '');
$videoSrc = Arr::get($settings, 'src', '');
$hasHlsCaptions = ($provider === 'mux' || $provider === 'mux_stream' || $provider === 'bunny' || strpos($videoSrc, '.m3u8') !== false);
$showCaptionMenu = $hasCustomSubtitles || $hasHlsCaptions;

if (!$hasPro && !$hasHlsCaptions) {
    return;
}

// Find the default subtitle track
$defaultSubtitle = null;
if ($hasCustomSubtitles) {
    $defaultFound = false;
    foreach ($subtitles as $subtitle) {
        if (Arr::get($subtitle, 'is_default', false)) {
            $defaultSubtitle = $subtitle;
            $defaultFound = true;
            break;
        }
    }
    if (!$defaultFound && !empty($subtitles)) {
        $defaultSubtitle = $subtitles[0];
    }
}
?>

<?php if (!empty($subtitlesWithUrl) && !$showCaptionMenu): ?>
    <media-tooltip>
        <media-tooltip-trigger>
            <media-caption-button class="fp-media-button" aria-label="<?php echo esc_attr__('Captions', 'fluent-player'); ?>">
                <media-icon type="closed-captions" class="fp-media-cc-on-icon"></media-icon>
                <media-icon type="closed-captions-on" class="fp-media-cc-off-icon"></media-icon>
            </media-caption-button>
        </media-tooltip-trigger>
        <media-tooltip-content class="fp-media-tooltip" placement="top">
            <span><?php echo esc_html__('Captions', 'fluent-player'); ?></span>
        </media-tooltip-content>
    </media-tooltip>
<?php elseif ($showCaptionMenu): ?>
    <media-menu class="fp-subtitle-menu">
        <media-tooltip>
            <media-tooltip-trigger>
                <media-menu-button class="fp-media-button fp-subtitle-menu-button" aria-label="<?php echo esc_attr__('Captions', 'fluent-player'); ?>">
                    <media-icon type="subtitles"></media-icon>
                </media-menu-button>
            </media-tooltip-trigger>
            <media-tooltip-content class="fp-media-tooltip" placement="top">
                <span><?php echo $defaultSubtitle ? esc_html($defaultSubtitle['label'] ?? $defaultSubtitle['language']) : esc_html__('Captions', 'fluent-player'); ?></span>
            </media-tooltip-content>
        </media-tooltip>
        <media-menu-items class="fp-subtitle-menu-items" placement="top end">
            <media-captions-radio-group class="fp-media-radio-group fp-subtitle-radio-group" off-label="<?php echo esc_attr__('Off', 'fluent-player'); ?>">
                <template>
                    <media-radio class="fp-subtitle-radio">
                        <div class="fp-media-radio-check"></div>
                        <span class="fp-media-radio-label" data-part="label"></span>
                    </media-radio>
                </template>
            </media-captions-radio-group>
        </media-menu-items>
    </media-menu>
<?php endif; ?>
