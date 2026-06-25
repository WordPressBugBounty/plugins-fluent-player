<?php
/**
 * Center Controls
 */
if (!defined('ABSPATH')) exit;

?>
<!-- Controls Center -->
<media-controls class="fp-media-controls-center fp-skin-<?php echo esc_attr($skin); ?>">
    <media-controls-group class="fp-controls-group">
        <?php include __DIR__ . '/controls/play-button.php'; ?>
    </media-controls-group>
</media-controls>
