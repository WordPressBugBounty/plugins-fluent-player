<?php
/**
 * PIP Button — native <media-pip-button> for video providers; a custom
 * float-toggle button for iframe providers (YouTube/Vimeo), which can't do
 * native PiP.
 *
 * @var array       $settings
 * @var string|null $provider
 * @var bool|null   $isPlaylist
 */
if (!defined('ABSPATH')) exit;

$pipProvider = isset($provider)
    ? $provider
    : \FluentPlayer\Framework\Support\Arr::get($settings ?? [], 'provider', '');
$isPlaylist = isset($isPlaylist) ? (bool) $isPlaylist : false;
$isIframePipProvider = in_array(strtolower((string) $pipProvider), ['youtube', 'vimeo'], true);

// Playlists use .playlist-player-container + a different runtime, so the float
// button can't engage there — only offer it for single-media players.
$useFloatButton = apply_filters(
    'fluent_player/pip_use_float_button',
    $isIframePipProvider && !$isPlaylist,
    $pipProvider,
    $settings ?? []
);

?>
<?php if ($useFloatButton): ?>
<media-tooltip>
    <media-tooltip-trigger>
        <button type="button" class="fp-media-button fp-media-float-button" data-fp-float-toggle aria-pressed="false" aria-label="<?php echo esc_attr__('Picture in Picture', 'fluent-player'); ?>">
            <svg class="vds-icon" width="100%" height="100%" viewBox="0 0 32 32" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
                <path d="M16 15.3333C15.6318 15.3333 15.3333 15.6318 15.3333 16V20C15.3333 20.3682 15.6318 20.6667 16 20.6667H21.3333C21.7015 20.6667 22 20.3682 22 20V16C22 15.6318 21.7015 15.3333 21.3333 15.3333H16Z" fill="currentColor"></path>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M5.33333 7.33334C5.33333 6.96515 5.63181 6.66667 5.99999 6.66667H26C26.3682 6.66667 26.6667 6.96515 26.6667 7.33334V24.6667C26.6667 25.0349 26.3682 25.3333 26 25.3333H5.99999C5.63181 25.3333 5.33333 25.0349 5.33333 24.6667V7.33334ZM7.99999 10C7.99999 9.63182 8.29847 9.33334 8.66666 9.33334H23.3333C23.7015 9.33334 24 9.63182 24 10V22C24 22.3682 23.7015 22.6667 23.3333 22.6667H8.66666C8.29847 22.6667 7.99999 22.3682 7.99999 22V10Z" fill="currentColor"></path>
            </svg>
        </button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span><?php echo esc_html__('Picture in Picture', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>
<?php else: ?>
<media-tooltip>
    <media-tooltip-trigger>
        <media-pip-button class="fp-media-button" aria-label="<?php echo esc_attr__('Picture in Picture', 'fluent-player'); ?>">
            <media-icon class="fp-media-pip-enter-icon" type="picture-in-picture"></media-icon>
            <media-icon class="fp-media-pip-exit-icon" type="picture-in-picture-exit"></media-icon>
        </media-pip-button>
    </media-tooltip-trigger>
    <media-tooltip-content class="fp-media-tooltip" placement="top">
        <span class="fp-media-pip-enter-tooltip-text"><?php echo esc_html__('Enter PiP', 'fluent-player'); ?></span>
        <span class="fp-media-pip-exit-tooltip-text"><?php echo esc_html__('Exit PiP', 'fluent-player'); ?></span>
    </media-tooltip-content>
</media-tooltip>
<?php endif; ?>