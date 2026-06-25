<?php
/**
 * Call To Action Overlay Component
 *
 * @var array $settings
 * @var int $media_id
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\Framework\Support\Arr;

if (!defined('ABSPATH')) exit;

// Only render if CTA is enabled
if (!Arr::get($settings, 'cta.enabled')) {
    return;
}

$content = Arr::get($settings, 'cta.content', '');
$completion_type = Arr::get($settings, 'cta.completion_type', 'link_click');
$auto_dismiss_duration = Arr::get($settings, 'cta.auto_dismiss_duration', 5);
$allow_skip = Arr::get($settings, 'cta.allow_skip', true);
$bg_color = Arr::get($settings, 'cta.bg_color', '#0E121B');
$text_color = Arr::get($settings, 'cta.text_color', '#ffffff');
$preset_slug = Arr::get($settings, 'preset_slug', '');

$show_skip = ($completion_type === 'skip_only') || $allow_skip;
?>

<div class="cta-overlay" style="display: none; --cta-bg-color: <?php echo esc_attr($bg_color); ?>; --cta-text-color: <?php echo esc_attr($text_color); ?>;"
     role="dialog"
     aria-modal="true"
     aria-label="<?php esc_attr_e('Call to action', 'fluent-player'); ?>"
     data-media-id="<?php echo esc_attr($media_id); ?>"
     data-preset-slug="<?php echo esc_attr($preset_slug); ?>"
     data-completion-type="<?php echo esc_attr($completion_type); ?>"
     data-auto-dismiss="<?php echo esc_attr($auto_dismiss_duration); ?>"
     data-allow-skip="<?php echo esc_attr($allow_skip ? 'true' : 'false'); ?>"
     data-nonce="<?php echo esc_attr(wp_create_nonce('fluent_player_cta_nonce')); ?>">
    <div class="cta-container">
        <?php if ($show_skip): ?>
        <button type="button" class="cta-skip" aria-label="<?php esc_attr_e('Skip', 'fluent-player'); ?>">
            <span class="cta-skip-text"><?php echo esc_html__('Skip', 'fluent-player'); ?> </span><span class="cta-skip-icon" aria-hidden="true">→</span>
        </button>
        <?php endif; ?>

        <?php if (!empty($content)): ?>
            <div class="cta-text-content" dir="auto"><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
            echo \FluentPlayer\App\Helpers\Helper::ensureLinksNewTab(fluentplayer_sanitize_html($content));
            ?></div>
        <?php endif; ?>
    </div>
</div>
