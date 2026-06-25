<?php

namespace FluentPlayer\App\Views\Layers;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Services\LayerService;
use FluentPlayer\Framework\Support\Arr;

if (!defined('ABSPATH')) exit;

class LayerRenderer
{
    /**
     * Render all layers data for JavaScript consumption
     */
    public static function renderLayersData($settings, $media_id)
    {
        if (empty($settings['layers'])) {
            return '';
        }

        ob_start();
        ?>
        <div id="fluent-layers-data-<?php echo esc_attr($media_id); ?>" class="fluent-player-layers-wrapper" style="display: none;" data-media-id="<?php echo esc_attr($media_id); ?>">
            <?php foreach ($settings['layers'] as $layer): ?>
                <?php if ($layerContent = self::renderLayerContent($layer, $media_id)): ?>
                  <div class="fluent-player-layer-template" data-layer-id="<?php echo esc_attr($layer['id']); ?>">
                      <?php
                      // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML content is escaped within the view
                      echo $layerContent
                      ?>
                  </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render layer content based on type
     */
    private static function renderLayerContent($layer, $mediaId)
    {
        switch ($layer['type']) {
            case 'cta':
                if ('email' === Arr::get($layer, 'cta_type')) {
                    return self::renderEmailLayer($layer, $mediaId);
                }
                return self::renderCTALayer($layer);
            case 'hotspot':
                return self::renderHotspotLayer($layer);
            case 'form':
                return self::renderFormLayer($layer);
            case 'ad':
                return self::renderAdLayer($layer);
            case 'shortcode':
                return self::renderShortcodeLayer($layer);
            default:
                return '';
        }
    }

    /**
     * Render CTA layer content
     */
    private static function renderCTALayer($layer)
    {
        $bg_color = Arr::get($layer, 'bg_color', '');
        $bg_style = $bg_color !== '' ? 'background-color: ' . esc_attr($bg_color) . ';' : '';

        ob_start();
        ?>
        <div class="fluent-player-layer-cta-text-content"<?php echo $bg_style !== '' ? ' style="' . esc_attr($bg_style) . '"' : ''; ?>>
            <div class="cta-text-wrapper">
                <?php if (!empty($layer['content'])): ?>
                    <div class="cta-text-content"><?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
                    echo \FluentPlayer\App\Helpers\Helper::ensureLinksNewTab(fluentplayer_sanitize_html($layer['content']));
                    ?></div>
                <?php endif; ?>
            </div>
            <?php
            $allowSkip = Arr::get($layer, 'allow_skip', true);
            $completionType = Arr::get($layer, 'completion_type', 'link_click');
            if ($allowSkip || $completionType === 'skip_only'):
            ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
                echo fluentplayer_sanitize_html(self::renderSkipButton());
                ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render CTA layer content
     */
    private static function renderEmailLayer($layer, $mediaId)
    {
        $bg_color = $layer['bg_color'] ?? '#FFFFFFB3';
        $border_radius = Arr::get($layer, 'email_capture.border_radius', 0);
        $headline = Arr::get($layer, 'email_capture.headline', '');
        $placeholder = Arr::get($layer, 'email_capture.placeholder', '');
        $button_text = Arr::get($layer, 'email_capture.button_text', '');
        $button_bg_color = Arr::get($layer, 'email_capture.button_bg_color', '');
        $button_color = Arr::get($layer, 'email_capture.button_color', '');
        $bottom_text = Arr::get($layer, 'email_capture.bottom_text', '');
        $layerId = Arr::get($layer, 'id', '');
        $nonceAction = \FluentPlayer\App\Services\EmailCollectionService::getNonceAction(
            'layer',
            $mediaId,
            '',
            $layerId
        );
        $layer_btn_bg_style = $button_bg_color !== '' ? 'background-color: ' . esc_attr($button_bg_color) . ';' : '';
        $layer_btn_color_style = $button_color !== '' ? 'color: ' . esc_attr($button_color) . ';' : '';
        ob_start();
        ?>
        <div class="fluent-player-layer-cta-email-content" style="background-color: <?php echo esc_attr($bg_color); ?>;">
          <div class="media-email-capture-overlay active" style="--email-capture-border-radius: <?php echo esc_attr($border_radius); ?>px;">
            <div class="email-capture-container">
              <h2 class="email-capture-headline"><?php echo esc_html($headline); ?></h2>

              <form class="email-capture-form">
                <input
                  type="email"
                  placeholder="<?php echo esc_attr($placeholder); ?>"
                  class="email-capture-input"
                  aria-label="<?php echo esc_attr(!empty($placeholder) ? $placeholder : __('Email address', 'fluent-player')); ?>"
                />
                <button
                  type="button"
                  class="email-capture-button"
                  style="<?php echo esc_attr($layer_btn_bg_style . ' ' . $layer_btn_color_style); ?>"
                  data-media-id="<?php echo esc_attr($mediaId); ?>"
                  data-layer-id="<?php echo esc_attr($layerId); ?>"
                  data-type="layer"
                  data-nonce="<?php echo esc_attr(wp_create_nonce($nonceAction)); ?>"
                >
                    <?php echo esc_html($button_text); ?>
                </button>
              </form>

              <p class="email-capture-bottom-text"><?php echo esc_html($bottom_text); ?></p>
              <div class="email-capture-message" role="alert" aria-live="assertive" style="display: none;"></div>
            </div>
          </div>
          <?php if ($layer['allow_skip'] ?? true): ?>
              <?php
              // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
              echo fluentplayer_sanitize_html(self::renderSkipButton());
              ?>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function renderAdLayer($layer)
    {
        $media_url = $layer['media_url'] ?? $layer['video_url'] ?? '';
        $media_type = $layer['media_type'] ?? 'video';
        $skip_offset = $layer['skip_offset'] ?? 0;
        
        if (empty($media_url)) {
            return '';
        }

        ob_start();
        ?>
        <div class="fluent-player-layer-ad-content" data-media-type="<?php echo esc_attr($media_type); ?>" data-skip-offset="<?php echo esc_attr($skip_offset); ?>">
            <div>
                <?php if ($media_type === 'image'): ?>
                    <img src="<?php echo esc_url($media_url); ?>" alt="<?php esc_attr_e('Advertisement', 'fluent-player'); ?>" />
                <?php else: ?>
                    <video src="<?php echo esc_url($media_url); ?>" aria-label="<?php esc_attr_e('Advertisement', 'fluent-player'); ?>"></video>
                <?php endif; ?>
                <div class="fluent-player-layer-ad-time-remaining-bar">
                    <div class="fluent-player-layer-ad-time-remaining-bar-fill"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }


    /**
     * Render hotspot layer content
     */
    private static function renderHotspotLayer($layer)
    {
        if (empty($layer['hotspots'])) {
            return '';
        }

        $bg_color = $layer['bg_color'] ?? 'transparent';
        
        ob_start();
        ?>
        <div class="fluent-player-hotspot-content" style="background-color: <?php echo esc_attr($bg_color); ?>;">
            <?php foreach ($layer['hotspots'] as $index => $hotspot):
                $hotspot_label = Arr::get($hotspot, 'tooltip_text') ?: __('Hotspot', 'fluent-player');
                $posY = (float) ($hotspot['position_y'] ?? 50);
                $posX = (float) ($hotspot['position_x'] ?? 50);
                $tooltipClasses = [];
                if ($posY < 15) {
                    $tooltipClasses[] = 'tooltip-bottom';
                }
                if ($posX < 15) {
                    $tooltipClasses[] = 'tooltip-left';
                } elseif ($posX > 85) {
                    $tooltipClasses[] = 'tooltip-right';
                }
                $markerClasses = ($hotspot['type'] ?? 'dot') === 'icon' ? 'icon-type' : '';
                if ($tooltipClasses) {
                    $markerClasses .= ' ' . implode(' ', $tooltipClasses);
                }
            ?>
                <div class="fluent-player-hotspot-marker <?php echo esc_attr(trim($markerClasses)); ?>"
                     style="left: <?php echo esc_attr($hotspot['position_x'] ?? 50); ?>%;
                            top: <?php echo esc_attr($hotspot['position_y'] ?? 50); ?>%;
                            width: <?php echo esc_attr($hotspot['size'] ?? 48); ?>px;
                            height: <?php echo esc_attr($hotspot['size'] ?? 48); ?>px;
                            background-color: <?php $hotspot_bg = Arr::get($hotspot, 'background_color', ''); echo esc_attr($hotspot_bg !== '' ? $hotspot_bg : 'transparent'); ?>;"
                     data-hotspot-id="<?php echo esc_attr($hotspot['id'] ?? $index); ?>"
                     data-action="hotspot-click"
                     role="button"
                     tabindex="0"
                     aria-label="<?php echo esc_attr($hotspot_label); ?>">
                    
                    <?php if (($hotspot['type'] ?? 'dot') === 'icon' && !empty($hotspot['icon'])): ?>
                        <?php echo self::renderIcon($hotspot['icon']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons are sanitized in renderIcon method ?>
                    <?php else: ?>
                        <div class="fluent-player-hotspot-dot"></div>
                    <?php endif; ?>

                    <?php if (!empty($hotspot['tooltip_text'])): ?>
                        <div class="fluent-player-hotspot-tooltip">
                            <?php if (!empty($hotspot['link'])): ?>
                                <a data-action="hotspot-click" href="<?php echo esc_url($hotspot['link']); ?>" target="_blank">
                                    <?php echo esc_html($hotspot['tooltip_text']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($hotspot['tooltip_text']); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render form layer content
     */
    private static function renderFormLayer($layer)
    {
        $bg_color = $layer['bg_color'] ?? '';
        $text_color = $layer['text_color'] ?? '';
        $form_type = $layer['form_type'] ?? '';
        $form_id = $layer['form_id'] ?? '';
        $width = Arr::get($layer, 'width', 90);
        $height = Arr::get($layer, 'height', 90);
        $position = Arr::get($layer, 'position', 'center');

        if (empty($form_type) || empty($form_id) || !LayerService::isFormPluginActive($form_type)) {
            return '';
        }

        $position = str_replace('position-', '', $position);
        $content_styles = '';
        if ($bg_color !== '' && $bg_color !== 'transparent') {
            $content_styles .= 'background-color: ' . esc_attr($bg_color) . ';';
        }
        if ($text_color !== '' && $text_color !== 'transparent') {
            $content_styles .= ' color: ' . esc_attr($text_color) . ';';
        }
        ob_start();
        ?>
        <div class="fluent-player-layer-form-content position-<?php echo esc_attr($position); ?>"<?php if ($content_styles !== '') : ?> style="<?php echo esc_attr($content_styles); ?>"<?php endif; ?>>
            <?php if (!empty($layer['title'])): ?>
                <h3 class="fluent-player-layer-form-title"><?php echo esc_html($layer['title']); ?></h3>
            <?php endif; ?>

            <div class="fluent-player-layer-form-container" style="color: inherit; width: <?php echo esc_attr($width); ?>%; height: <?php echo esc_attr($height); ?>%; max-width: <?php echo esc_attr($width); ?>%; max-height: <?php echo esc_attr($height); ?>%;">
                <?php if ($form_type && $form_id): ?>
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form content is sanitized by form plugin
                    echo self::renderFormByType($form_type, $form_id);
                    ?>
                <?php else: ?>
                    <p class="form-placeholder"><?php echo \esc_html(__('No form selected', 'fluent-player')); ?></p>
                <?php endif; ?>
            </div>
            
            <?php if ($layer['allow_skip'] ?? true): ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
                echo fluentplayer_sanitize_html(self::renderSkipButton());
                ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render form by type
     */
    private static function renderFormByType($formType, $formId)
    {
        switch ($formType) {
            case 'fluentforms':
                $shortcode = '[fluentform id="' . \esc_attr($formId) . '"]';
                if (\FluentForm\App\Helpers\Helper::isConversionForm($formId)) {
                    $shortcode = '[fluentform type="conversational" id="' . \esc_attr($formId) . '"]';
                }
                return do_shortcode($shortcode);
            default:
                return '<p class="fluent-player-layer-form-error">' . \esc_html(__('Unsupported form type', 'fluent-player')) . '</p>';
        }
    }

    /**
     * Render skip button
     */
    private static function renderSkipButton()
    {
        return '<button type="button" class="fluent-player-skip-button" data-action="skip" aria-label="' . esc_attr__('Skip', 'fluent-player') . '">' . esc_html__('Skip', 'fluent-player') . ' <span aria-hidden="true">→</span></button>';
    }

    /**
     * Render icon (simplified version)
     */
    private static function renderIcon($icon_name)
    {
        $icons = Helper::getSvgIcons();
        if ($iconPath = Arr::get($icons, $icon_name, '')) {
            return '<svg class="hotspot-icon" fill="#ffffff" viewBox="0 0 512 512" aria-hidden="true" focusable="false">' . wp_kses($iconPath, ['path' => ['d' => []]]) . '</svg>';
        }
        return '';
    }

    /**
     * Render shortcode layer content
     */
    private static function renderShortcodeLayer($layer)
    {
        $bg_color = $layer['bg_color'] ?? '#FFFFFFB3';
        $width = $layer['width'] ?? 80;
        
        ob_start();
        ?>
        <div class="fluent-player-layer-shortcode-content" style="background-color: <?php echo esc_attr($bg_color); ?>;">
            <div class="shortcode-layer-wrapper" style="width: <?php echo esc_attr($width); ?>%; max-width: <?php echo esc_attr($width); ?>%;">
                <?php if (!empty($layer['title'])): ?>
                    <h3 class="shortcode-layer-title"><?php echo esc_html($layer['title']); ?></h3>
                <?php endif; ?>
                
                <div class="shortcode-content">
                    <?php if (!empty($layer['shortcode'])): ?>
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode content is sanitized by shortcode handler
                        echo do_shortcode($layer['shortcode']);
                        ?>
                    <?php else: ?>
                        <p class="shortcode-placeholder"><?php echo esc_html(__('No shortcode configured', 'fluent-player')); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
            echo fluentplayer_sanitize_html(self::renderSkipButton());
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
