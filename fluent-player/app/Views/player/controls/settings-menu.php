<?php
/**
 * Settings Menu
 * $settings is in scope from parent view
 * $controls is in scope from parent view (bottom-controls.php)
 */
if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Helpers\Helper;

$fluent_player_video_src = Arr::get($settings, 'src', '');
$fluent_player_provider = Arr::get($settings, 'provider', '');
$fluent_player_has_hls = ($fluent_player_provider === 'bunny' || $fluent_player_provider === 'mux' || $fluent_player_provider === 'mux_stream' || strpos($fluent_player_video_src, '.m3u8') !== false);
$fluent_player_preset_slug = Arr::get($settings, 'preset_slug', Arr::get($settings, 'slug', ''));
$fluent_player_is_simple_preset = ($fluent_player_preset_slug === 'simple');
$fluent_player_has_subtitles = Arr::get($controls, 'captions_toggle', true)
    && (
        (Helper::hasPro() && !empty(Arr::get($settings, 'subtitles', [])))
        || $fluent_player_has_hls
    );
$fluent_player_has_playback_speed = !$fluent_player_is_simple_preset && Arr::get($controls, 'playback_speed', true);
$fluent_player_has_accessibility = !$fluent_player_is_simple_preset;
$fluent_player_has_quality = Arr::get($controls, 'quality', true) && $fluent_player_has_hls;

if (!$fluent_player_has_playback_speed && !$fluent_player_has_accessibility && !$fluent_player_has_quality && !$fluent_player_has_subtitles) {
    return;
}
?>
<media-menu class="fp-settings-menu">
    <media-tooltip>
        <media-tooltip-trigger>
            <media-menu-button class="fp-media-button" aria-label="<?php echo esc_attr__('Settings', 'fluent-player'); ?>">
                <media-icon type="settings"></media-icon>
            </media-menu-button>
        </media-tooltip-trigger>
        <media-tooltip-content class="fp-media-tooltip" placement="top">
            <span><?php esc_html_e('Settings', 'fluent-player'); ?></span>
        </media-tooltip-content>
    </media-tooltip>
    <media-menu-items placement="top end">
        <?php if ($fluent_player_has_playback_speed): ?>
        <media-menu>
            <media-menu-button class="fp-media-menu-button" aria-label="<?php echo esc_attr__('Playback', 'fluent-player'); ?>">
                <media-icon type="odometer"></media-icon>
                <span class="fp-media-menu-button-label"><?php esc_html_e('Playback', 'fluent-player'); ?></span>
                <span class="fp-media-menu-button-hint" data-part="hint"></span>
                <media-icon
                    class="fp-media-menu-button-open-icon"
                    type="chevron-right"
                ></media-icon>
            </media-menu-button>
            <!-- Playback Submenu Items (Speed) -->
            <media-menu-items aria-label="<?php echo esc_attr__('Playback', 'fluent-player'); ?>">
                <media-speed-radio-group class="fp-media-radio-group">
                    <template>
                        <media-radio>
                            <div class="fp-media-radio-check"></div>
                            <span class="fp-media-radio-label" data-part="label"></span>
                        </media-radio>
                    </template>
                </media-speed-radio-group>
            </media-menu-items>
        </media-menu>
        <?php endif; ?>
        <?php if ($fluent_player_has_accessibility): ?>
        <media-menu>
            <media-menu-button class="fp-media-menu-button" aria-label="<?php echo esc_attr__('Accessibility', 'fluent-player'); ?>">
                <media-icon type="accessibility"></media-icon>
                <span class="fp-media-menu-button-label"><?php esc_html_e('Accessibility', 'fluent-player'); ?></span>
                <media-icon
                    class="fp-media-menu-button-open-icon"
                    type="chevron-right"
                ></media-icon>
            </media-menu-button>
            <!-- Accessibility Submenu Items -->
            <media-menu-items aria-label="<?php echo esc_attr__('Accessibility', 'fluent-player'); ?>">
                <div class="fp-menu-section">
                    <div class="fp-menu-item">
                        <div class="fp-menu-item-label"><?php esc_html_e('Announcements', 'fluent-player'); ?></div>
                        <div class="fp-menu-checkbox" role="menuitemcheckbox" tabindex="0" aria-label="<?php echo esc_attr__('Announcements', 'fluent-player'); ?>" aria-checked="false" data-fp-toggle="announcements"></div>
                    </div>
                    <div class="fp-menu-item">
                        <div class="fp-menu-item-label"><?php esc_html_e('Keyboard Animations', 'fluent-player'); ?></div>
                        <div class="fp-menu-checkbox" role="menuitemcheckbox" tabindex="0" aria-label="<?php echo esc_attr__('Keyboard Animations', 'fluent-player'); ?>" aria-checked="true" data-fp-toggle="keyboard-animations"></div>
                    </div>
                </div>
            </media-menu-items>
        </media-menu>
        <?php endif; ?>
        <?php if ($fluent_player_has_quality): ?>
        <media-menu>
            <media-menu-button class="fp-media-menu-button" aria-label="<?php echo esc_attr__('Quality', 'fluent-player'); ?>">
                <media-icon type="settings"></media-icon>
                <span class="fp-media-menu-button-label"><?php esc_html_e('Quality', 'fluent-player'); ?></span>
                <span class="fp-media-menu-button-hint" data-part="hint"></span>
                <media-icon
                    class="fp-media-menu-button-open-icon"
                    type="chevron-right"
                ></media-icon>
            </media-menu-button>
            <!-- Quality Submenu Items -->
            <media-menu-items aria-label="<?php echo esc_attr__('Quality', 'fluent-player'); ?>">
                <media-quality-radio-group class="fp-media-radio-group">
                    <template>
                        <media-radio>
                            <div class="fp-media-radio-check"></div>
                            <span class="fp-media-radio-label" data-part="label"></span>
                        </media-radio>
                    </template>
                </media-quality-radio-group>
            </media-menu-items>
        </media-menu>
        <?php endif; ?>
        <?php if ($fluent_player_has_subtitles): ?>
        <media-menu class="fp-settings-captions-menu">
            <media-menu-button class="fp-media-menu-button" aria-label="<?php echo esc_attr__('Captions', 'fluent-player'); ?>">
                <media-icon type="subtitles"></media-icon>
                <span class="fp-media-menu-button-label"><?php esc_html_e('Captions', 'fluent-player'); ?></span>
                <span class="fp-media-menu-button-hint" data-part="hint"></span>
                <media-icon
                    class="fp-media-menu-button-open-icon"
                    type="chevron-right"
                ></media-icon>
            </media-menu-button>
            <!-- Caption Submenu Items -->
            <media-menu-items aria-label="<?php echo esc_attr__('Captions', 'fluent-player'); ?>">
                <media-captions-radio-group class="fp-media-radio-group">
                    <template>
                        <media-radio>
                            <div class="fp-media-radio-check"></div>
                            <span class="fp-media-radio-label" data-part="label"></span>
                        </media-radio>
                    </template>
                </media-captions-radio-group>
            </media-menu-items>
        </media-menu>
        <?php endif; ?>
    </media-menu-items>
</media-menu>
