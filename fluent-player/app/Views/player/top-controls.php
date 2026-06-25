<?php
/**
 * Top Controls
 * Consolidated component that includes title overlay, chapter title, and language controls
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\Framework\Support\Arr;

if (!defined('ABSPATH')) exit;

// Get the video title and overlay setting
$videoTitle = Arr::get($settings, 'title', '');
$showTitleOverlay = Arr::get($settings, 'show_title_overlay', true);
$hasChapters = !empty(Arr::get($settings, 'chapters', []));
?>

<?php
// Include language controls - Shows language switcher at top right
include __DIR__ . '/language-controls.php';
?>

<?php if (!empty($videoTitle) && $showTitleOverlay): ?>
<!-- Title Overlay with Video Title and Chapter Title stacked -->
<div class="fluent-player-top-title-overlay">
    <div class="fluent-player-title-stack">
        <!-- Video Title - Main title -->
        <media-title class="fluent-player-media-title"></media-title>

        <?php if ($hasChapters): ?>
        <!-- Chapter Title - Subtitle below video title -->
        <media-chapter-title class="fluent-player-chapter-subtitle"></media-chapter-title>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (empty($videoTitle) || !$showTitleOverlay): ?>
<!-- Top Controls - Only show chapter title if no video title -->
<media-controls class="fp-media-controls-top fp-skin-<?php echo esc_attr($skin); ?>">
    <media-controls-group>
        <media-chapter-title></media-chapter-title>
        <div class="fp-media-controls-spacer"></div>
    </media-controls-group>
</media-controls>
<?php endif; ?>
