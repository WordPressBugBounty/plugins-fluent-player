<?php
/**
 * Action Bar Overlay Component
 * 
 * @var array $settings
 * @var int $media_id
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\Framework\Support\Arr;

if (!defined('ABSPATH')) exit;

// Only render if action bar is enabled
if (!Arr::get($settings, 'action_bar.enabled')) {
    return;
}

$button_text = Arr::get($settings, 'action_bar.button_text', '');
$text = Arr::get($settings, 'action_bar.text', __('Like this content?', 'fluent-player'));
$text_size = Arr::get($settings, 'action_bar.text_size', '14');
$background_color = Arr::get($settings, 'action_bar.background_color', '');
$button_color = Arr::get($settings, 'action_bar.button_color', '');
$button_text_color = Arr::get($settings, 'action_bar.button_text_color', '');
$button_radius = Arr::get($settings, 'action_bar.button_radius', 4);
$button_link = Arr::get($settings, 'action_bar.button_link', '');
$button_type = Arr::get($settings, 'action_bar.button_type', 'custom');
$open_in_new_tab = Arr::get($settings, 'action_bar.open_in_new_tab', true);
$preset_slug = Arr::get($settings, 'preset_slug', '');
$position = Arr::get($settings, 'action_bar.position', 'bottom');
$youtube_channel = Arr::get($settings, 'action_bar.youtube_channel', '');
$button_count = Arr::get($settings, 'action_bar.button_count', false);
$subscriber_count = Arr::get($settings, 'action_bar.subscriber_count', '');
$show_close = Arr::get($settings, 'action_bar.show_close', false);
$close_button_color = Arr::get($settings, 'action_bar.close_button_color', '');
$close_button_text_color = Arr::get($settings, 'action_bar.close_button_text_color', '');
$text_color = Arr::get($settings, 'action_bar.text_color', '');
$action_bar_bg_style = $background_color !== '' ? 'background-color: ' . esc_attr($background_color) . ';' : '';
$action_bar_text_color_style = $text_color !== '' ? ' color: ' . esc_attr($text_color) . ';' : '';
?>

<div class="action-bar-overlay action-bar-position-<?php echo esc_attr($position); ?>" 
     style="<?php echo esc_attr($action_bar_bg_style . $action_bar_text_color_style); ?> display: none;">
    <div class="action-bar-content">
        <div class="action-bar-text" dir="auto" style="font-size: <?php echo esc_attr($text_size); ?>px;">
            <?php echo esc_html($text); ?>
        </div>
        
        <?php if ($button_type === 'custom' && $button_text !== ''): ?>
        <?php
        $action_btn_style = ($button_color !== '' ? 'background-color: ' . esc_attr($button_color) . ';' : '') . ($button_text_color !== '' ? ' color: ' . esc_attr($button_text_color) . ';' : '') . ' border-radius: ' . esc_attr($button_radius) . 'px;';
        ?>
        <button
            type="button"
            class="action-bar-button"
            dir="auto"
            data-media-id="<?php echo esc_attr($media_id); ?>"
            data-preset-slug="<?php echo esc_attr($preset_slug); ?>"
            data-link="<?php echo esc_url($button_link); ?>"
            data-target="<?php echo esc_attr($open_in_new_tab ? '_blank' : '_self'); ?>"
            style="<?php echo esc_attr($action_btn_style); ?>"
        >
            <?php echo esc_html($button_text); ?>
        </button>
        <?php endif; ?>
        
        <?php if ($button_type === 'youtube' && $youtube_channel): ?>
        <button
            type="button"
            class="action-bar-youtube-button"
            data-media-id="<?php echo esc_attr($media_id); ?>"
            data-preset-slug="<?php echo esc_attr($preset_slug); ?>"
            data-channel="<?php echo esc_attr($youtube_channel); ?>"
            data-target="_blank"
        >
            <span class="youtube-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" fill="currentColor"/>
                </svg>
            </span>
            <span class="youtube-text">YouTube</span>
            <?php if ($button_count && $subscriber_count): ?>
            <span class="subscriber-count"><?php echo esc_html($subscriber_count); ?></span>
            <?php endif; ?>
        </button>
        <?php endif; ?>
    </div>
    
    <?php if ($show_close): ?>
    <?php
    $close_btn_style = ($close_button_color !== '' ? 'background-color: ' . esc_attr($close_button_color) . ';' : '') . ($close_button_text_color !== '' ? ' color: ' . esc_attr($close_button_text_color) . ';' : '');
    ?>
    <button
        type="button"
        class="action-bar-close"
        aria-label="<?php esc_attr_e('Close action bar', 'fluent-player'); ?>"
        style="<?php echo esc_attr($close_btn_style); ?>"
    >
        <span class="action-bar-close-icon">
            <svg width="20" height="20" viewBox="0 -2 24 24" fill="none" aria-hidden="true" focusable="false">
                <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="3"></path>
            </svg>
        </span>
    </button>
    <?php endif; ?>
</div>
