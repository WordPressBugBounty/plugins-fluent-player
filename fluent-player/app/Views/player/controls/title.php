<?php
/**
 * Media Title
 * Using top-title-overlay control to show title we can remove it later
 */
if (!defined('ABSPATH')) exit;

?>
<div class="fp-media-title" dir="auto">
    <media-title title="<?php echo esc_attr(\FluentPlayer\Framework\Support\Arr::get($settings, 'title', '')); ?>"></media-title>
</div>
