<?php
/**
 * Email Capture Overlay Component
 * 
 * @var array $settings
 * @var int $media_id
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

use FluentPlayer\Framework\Support\Arr;

if (!defined('ABSPATH')) exit;

// Only render if email capture is enabled
if (!Arr::get($settings, 'email_capture.enabled')) {
    return;
}

$border_radius = Arr::get($settings, 'email_capture.border_radius', 0);
$headline = Arr::get($settings, 'email_capture.headline', __('Sign up to continue watching', 'fluent-player'));
$placeholder = Arr::get($settings, 'email_capture.placeholder', __('Email Address', 'fluent-player'));
$button_text = Arr::get($settings, 'email_capture.button_text', __('Subscribe', 'fluent-player'));
$button_bg_color = Arr::get($settings, 'email_capture.button_bg_color', '');
$button_color = Arr::get($settings, 'email_capture.button_color', '');
$bottom_text = Arr::get($settings, 'email_capture.bottom_text', __('We respect your privacy. Unsubscribe at any time.', 'fluent-player'));
$allow_skip = Arr::get($settings, 'email_capture.allow_skip', true);
$preset_slug = Arr::get($settings, 'preset_slug', '');
$nonceAction = \FluentPlayer\App\Services\EmailCollectionService::getNonceAction(
    'preset',
    $media_id,
    $preset_slug,
    ''
);
$button_style = trim(($button_bg_color !== '' ? 'background-color: ' . esc_attr($button_bg_color) . ';' : '') . ($button_color !== '' ? ' color: ' . esc_attr($button_color) . ';' : ''));
?>

<div class="media-email-capture-overlay" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($headline); ?>" style="--email-capture-border-radius: <?php echo esc_attr($border_radius); ?>px;" data-allow-skip="<?php echo esc_attr($allow_skip ? '1' : '0'); ?>">
    <div class="email-capture-container">
        <h2 class="email-capture-headline" dir="auto"><?php echo esc_html($headline); ?></h2>

        <form class="email-capture-form" aria-label="<?php echo esc_attr__('Email signup', 'fluent-player'); ?>">
            <input
                type="email"
                placeholder="<?php echo esc_attr($placeholder); ?>"
                aria-label="<?php echo esc_attr($placeholder); ?>"
                class="email-capture-input"
                dir="auto"
            />
            <button
                type="button"
                class="email-capture-button"
                dir="auto"
                style="<?php echo esc_attr($button_style); ?>"
                data-media-id="<?php echo esc_attr($media_id); ?>"
                data-preset-slug="<?php echo esc_attr($preset_slug); ?>"
                data-type="preset"
                data-nonce="<?php echo esc_attr(wp_create_nonce($nonceAction)); ?>"
            >
                <?php echo esc_html($button_text); ?>
            </button>
        </form>

        <p class="email-capture-bottom-text" dir="auto"><?php echo esc_html($bottom_text); ?></p>

        <?php if ($allow_skip): ?>
        <button type="button" class="email-capture-skip" aria-label="<?php echo esc_attr__('Skip email signup', 'fluent-player'); ?>">
            <span class="email-capture-skip-text"><?php echo esc_html__('Skip', 'fluent-player'); ?> </span><span class="email-capture-skip-icon" aria-hidden="true">&rarr;</span>
        </button>
        <?php endif; ?>

        <div class="email-capture-message" role="alert" aria-live="assertive" style="display: none;"></div>
    </div>
</div>
