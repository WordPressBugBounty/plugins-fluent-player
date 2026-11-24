<?php
/**
 * Share Menu — rendered when the caller passes $fluent_player_share: the link, title
 * and embed code of a video or playlist whose owner turned on "Allow sharing & embedding".
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\SettingsService;

if (empty($fluent_player_share)) {
    return;
}

$fluent_player_share_url = $fluent_player_share['url'];
$fluent_player_share_title_text = $fluent_player_share['title'];
$fluent_player_share_link = rawurlencode($fluent_player_share_url);
$fluent_player_share_title = rawurlencode($fluent_player_share_title_text);

$fluent_player_share_networks = [
    [
        'label' => __('Share on X', 'fluent-player'),
        'href'  => 'https://twitter.com/intent/tweet?url=' . $fluent_player_share_link . '&text=' . $fluent_player_share_title,
        'icon'  => '<path fill="currentColor" d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/>',
    ],
    [
        'label' => __('Share on Facebook', 'fluent-player'),
        'href'  => 'https://www.facebook.com/sharer/sharer.php?u=' . $fluent_player_share_link,
        'icon'  => '<path fill="currentColor" d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/>',
    ],
    [
        'label' => __('Share on LinkedIn', 'fluent-player'),
        // share-offsite only attaches a preview LinkedIn scrapes itself, so a page it
        // cannot fetch arrives empty; the composer text always carries the link.
        'href'  => 'https://www.linkedin.com/feed/?shareActive=true&text=' . rawurlencode(trim($fluent_player_share_title_text . ' ' . $fluent_player_share_url)),
        'icon'  => '<path fill="currentColor" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>',
    ],
    [
        'label' => __('Share by email', 'fluent-player'),
        'href'  => 'mailto:?subject=' . $fluent_player_share_title . '&body=' . $fluent_player_share_link,
        'icon'  => '<rect x="2" y="4" width="20" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
    ],
];

$fluent_player_share_svg_kses = [
    'path' => ['d' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true],
    'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true],
];
?>
<?php // esc_attr() keeps the code's own entities as-is, so the browser would decode them into live markup when the code is copied; double-encode instead. ?>
<?php if (!empty($fluent_player_share_in_settings)) : ?>
<media-menu class="fp-share-menu fp-share-menu--settings" data-embed-code="<?php echo htmlspecialchars($fluent_player_share['embed'], ENT_QUOTES, 'UTF-8', true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
    <media-menu-button class="fp-media-menu-button" aria-label="<?php echo esc_attr__('Share', 'fluent-player'); ?>">
        <media-icon type="share"></media-icon>
        <span class="fp-media-menu-button-label"><?php esc_html_e('Share', 'fluent-player'); ?></span>
        <media-icon class="fp-media-menu-button-open-icon" type="chevron-right"></media-icon>
    </media-menu-button>
    <media-menu-items class="fp-share-menu-items" aria-label="<?php echo esc_attr__('Share', 'fluent-player'); ?>">
<?php else : ?>
<media-menu class="fp-share-menu" data-embed-code="<?php echo htmlspecialchars($fluent_player_share['embed'], ENT_QUOTES, 'UTF-8', true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
    <media-tooltip>
        <media-tooltip-trigger>
            <media-menu-button class="fp-media-button" aria-label="<?php echo esc_attr__('Share', 'fluent-player'); ?>">
                <media-icon type="share"></media-icon>
            </media-menu-button>
        </media-tooltip-trigger>
        <media-tooltip-content class="fp-media-tooltip" placement="top">
            <span><?php esc_html_e('Share', 'fluent-player'); ?></span>
        </media-tooltip-content>
    </media-tooltip>
    <media-menu-items class="fp-share-menu-items" placement="top end" role="dialog" aria-label="<?php echo esc_attr__('Share', 'fluent-player'); ?>">
<?php endif; ?>
        <?php // Inside the settings menu the panel is a menu, so its controls must be menu items. ?>
        <button type="button" class="fp-share-item" data-share-action="copy-embed"<?php echo !empty($fluent_player_share_in_settings) ? ' role="menuitem"' : ''; ?>>
            <svg class="fp-share-code-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
            <span data-share-label aria-live="polite"><?php esc_html_e('Copy embed code', 'fluent-player'); ?></span>
        </button>
        <?php if (SettingsService::getSetting('general.share_social_links', true)) : ?>
        <div class="fp-share-networks" role="group" aria-label="<?php echo esc_attr__('Share on', 'fluent-player'); ?>">
            <?php foreach ($fluent_player_share_networks as $fluent_player_network) : ?>
                <a class="fp-share-network" href="<?php echo esc_url($fluent_player_network['href']); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr($fluent_player_network['label']); ?>"<?php echo !empty($fluent_player_share_in_settings) ? ' role="menuitem"' : ''; ?>>
                    <svg class="fp-share-network-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><?php echo wp_kses($fluent_player_network['icon'], $fluent_player_share_svg_kses); ?></svg>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </media-menu-items>
</media-menu>
