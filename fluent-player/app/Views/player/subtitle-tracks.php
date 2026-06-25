<?php
/**
 * Subtitle Tracks
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

if (!\FluentPlayer\App\Helpers\Helper::hasPro()) {
    return;
}

$subtitles = Arr::get($settings, 'subtitles', []);

if (empty($subtitles)) {
    return;
}

$defaultFound = false;
foreach ($subtitles as $subtitle) {
    if (Arr::get($subtitle, 'is_default', false)) {
        $defaultFound = true;
        break;
    }
}

foreach ($subtitles as $index => $subtitle):
    // Skip subtitles without a URL (e.g. Mux auto-captions embedded in HLS manifest)
    $subtitleUrl = $subtitle['url'] ?? '';
    if (empty($subtitleUrl)) {
        continue;
    }

    $isDefault = Arr::get($subtitle, 'is_default', false) || (!$defaultFound && $index === 0);
    $subtitleType = (strtolower(pathinfo($subtitleUrl, PATHINFO_EXTENSION)) === 'srt') ? 'srt' : 'vtt';
?>
    <track
        kind="subtitles"
        label="<?php echo esc_attr($subtitle['label'] ?? Arr::get($subtitle, 'language', '')); ?>"
        src="<?php echo esc_url($subtitleUrl); ?>"
        srclang="<?php echo esc_attr(Arr::get($subtitle, 'language', '')); ?>"
        type="<?php echo esc_attr($subtitleType); ?>"
        <?php if ($isDefault): ?>default<?php endif; ?>
    />
<?php
endforeach;
