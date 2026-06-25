<?php

/**
 * Language Controls
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

/** @var MediaSettings $settings  */
if (!Arr::get($settings, 'show_language_switcher', false)) {
    return;
}

$languageMappings = Arr::get($settings, 'language_mappings', []);
$currentLanguage = Arr::get($settings, 'language', 'en_US');

// Get current language info for button display
$currentLangInfo = \FluentPlayer\App\Helpers\Helper::getLanguageInfo($currentLanguage);
?>

<media-controls class="fluent-player-language-controls">
    <media-menu class="fluent-player-language-menu">
        
        <media-menu-button class="fluent-player-language-button fluent-player-language-button--styled"
                           aria-label="<?php echo esc_attr__('Language', 'fluent-player'); ?>">
            <span class="fluent-player-language-flag"><?php echo esc_html($currentLangInfo['flag']); ?></span>
            <span class="fluent-player-language-code"><?php echo esc_html($currentLangInfo['code']); ?></span>
            <media-icon class="fluent-player-language-icon" type="chevron-down"></media-icon>
        </media-menu-button>
        
        <media-menu-items class="fluent-player-language-menu-items" placement="bottom" offset="8">
            <media-radio-group class="fluent-player-language-radio-group fluent-player-language-switcher-radio-group"
                               aria-label="<?php echo esc_attr__('Language Options', 'fluent-player'); ?>"
                               data-media-id="<?php echo esc_attr($media_id); ?>"
                               value="<?php echo esc_attr($media_id); ?>">
                
                <?php
                // Show current media with its language (checked)
                \FluentPlayer\App\Helpers\Helper::renderLanguageOption($media_id, $currentLanguage, true);

                // Show all mapped languages
                foreach ($languageMappings as $mapping) {
                    $mappedMediaId = Arr::get($mapping, 'media_id');
                    $mappedLangCode = Arr::get($mapping, 'language');

                    if ($mappedMediaId && $mappedLangCode) {
                        $isChecked = ($mappedMediaId == $media_id);
                        \FluentPlayer\App\Helpers\Helper::renderLanguageOption($mappedMediaId, $mappedLangCode, $isChecked);
                    }
                }
                
                ?>

            </media-radio-group>
        </media-menu-items>
    </media-menu>
</media-controls>
