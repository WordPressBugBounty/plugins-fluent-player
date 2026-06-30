<?php
if (!defined('ABSPATH')) exit;

/**
 ***** DO NOT CALL ANY FUNCTIONS DIRECTLY FROM THIS FILE ******
 *
 * This file will be loaded even before the framework is loaded
 * so the $app is not available here, only declare functions here.
 */

if ($app->config->get('app.env') == 'dev') {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local variable in boot file, not a global
    $globalsDevFile = __DIR__ . '/../dev/globals.php';

    is_readable($globalsDevFile) && include $globalsDevFile;
}

if (!function_exists('wpfp_float_val')) {
    /**
     * PHP float val doesn't convert an int to float
     * so, this is just a wrapper to get a real number.
     *
     * @param  integer $val
     * @param  integer $frac
     * @return real number/float
     */
    function wpfp_float_val($val = 0, $frac = 2) {
        $val = floatval($val);

        if (strpos($val, '.') === false) {
            $val = sprintf("%.{$frac}f", $val);
        }

        return $val;
    }
}


if (!function_exists('wpfp_app')) {
    /**
     * Get the Fluent Player application instance using the App facade.
     *
     * This function provides access to the application container and all its services.
     *
     * @return \FluentPlayer\Framework\Foundation\Application
     */
    function wpfp_app() {
        return FluentPlayer\App\App::getInstance();
    }
}

if (!function_exists('wpfp_settings')) {
    /**
     * Get the Fluent Player global settings.
     *
     * @param  string|null $key     Optional key to retrieve a specific setting
     * @param  mixed       $default Default value if the key doesn't exist
     * @return mixed                The settings array or a specific setting value
     */
    function wpfp_settings($key = null, $default = null) {
        if (class_exists('\FluentPlayer\App\Services\SettingsService')) {
            if ($key === null) {
                return \FluentPlayer\App\Services\SettingsService::getSettings();
            }
            return \FluentPlayer\App\Services\SettingsService::getSetting($key, $default);
        }
        
        // Fallback if SettingsService is not available
        $settings = get_option('fluent_player_settings', []);

        if ($key === null) {
            return $settings;
        }

        return isset($settings[$key]) ? $settings[$key] : $default;
    }
}

if (!function_exists('wpfp_sanitize_css')) {
    function wpfp_sanitize_css($css)
    {
        if (!is_string($css)) {
            return '';
        }
        $css = preg_replace('/@import\s+url\s*\([^)]+\)\s*;/i', '', $css);
        $css = preg_replace('/@import\s+url\s*\(\s*[\'"]*\s*data\s*:/i', '', $css);
        $css = preg_replace('/expression\s*\(/i', '', $css);
        $css = preg_replace('/-moz-binding\s*:/i', '', $css);
        $css = preg_replace('/behavior\s*:\s*url\s*\(/i', '', $css);
        return preg_replace('/javascript\s*:/i', '', $css);
    }
}

if (!function_exists('fluentplayer_sanitize_html')) {
    /**
     * Sanitize HTML for Fluent Player output.
     * Allows custom web components like media-player, media-provider, etc.
     */
    function fluentplayer_sanitize_html($html)
    {
        if (!$html) {
            return $html;
        }

        // Remove event handlers (e.g., onerror, onclick, onmouseover)
        $html = preg_replace('/\s+on[a-z]+\s*=\s*([\'"])[^\'"]*\1/i', '', $html);

        // Remove JavaScript protocol (e.g., `href="javascript:alert(1)"`)
        $html = preg_replace('/\bjavascript\s*:/i', '', $html);

        // Start with standard post tags
        $tags = wp_kses_allowed_html('post');

        // NOTE: the raw <style> element is intentionally NOT allowed. wp_kses does
        // not sanitise CSS inside a <style> block, so permitting it would let
        // admin-authored CTA/layer content ship data-exfil or clickjacking
        // stylesheets to public viewers. The style="" attribute is still
        // allowed below (esc_attr keeps it from breaking out of the attribute).

        // Ensure style, class, id, and common attributes are allowed on all standard HTML elements
        foreach ($tags as $tag => &$attributes) {
            if (is_array($attributes)) {
                $attributes['style'] = true;
                $attributes['class'] = true;
                $attributes['id'] = true;
                $attributes['role'] = true;
                $attributes['tabindex'] = true;
                $attributes['aria-label'] = true;
                $attributes['aria-hidden'] = true;
                $attributes['aria-pressed'] = true;
                $attributes['aria-checked'] = true;
                $attributes['aria-disabled'] = true;
                $attributes['aria-expanded'] = true;
                $attributes['aria-haspopup'] = true;
                $attributes['aria-valuemin'] = true;
                $attributes['aria-valuemax'] = true;
                $attributes['aria-valuenow'] = true;
                $attributes['aria-valuetext'] = true;
                $attributes['aria-orientation'] = true;
                $attributes['aria-live'] = true;
                $attributes['aria-atomic'] = true;
                $attributes['aria-keyshortcuts'] = true;
                $attributes['aria-describedby'] = true;
            }
        }
        unset($attributes);

        // Ensure links have target and rel attributes
        if (isset($tags['a'])) {
            $tags['a']['target'] = true;
            $tags['a']['rel'] = true;
            $tags['a']['href'] = true;
        }

        // Ensure button tag allows disabled attribute and can contain SVG
        if (isset($tags['button'])) {
            $tags['button']['disabled'] = true;
            $tags['button']['type'] = true;
        }

        // Add form elements for playlist search/filters
        $tags['input'] = [
            'type' => true,
            'name' => true,
            'value' => true,
            'placeholder' => true,
            'class' => true,
            'id' => true,
            'style' => true,
            'disabled' => true,
            'readonly' => true,
            'maxlength' => true,
            'minlength' => true,
            'pattern' => true,
            'required' => true,
            'autocomplete' => true,
        ];
        
        $tags['select'] = [
            'name' => true,
            'class' => true,
            'id' => true,
            'style' => true,
            'disabled' => true,
            'multiple' => true,
            'required' => true,
        ];
        
        $tags['option'] = [
            'value' => true,
            'selected' => true,
            'disabled' => true,
            'class' => true,
        ];

        // Add custom media-player web components
        $mediaPlayerTags = [
            'media-player' => [
                'class' => true,
                'title' => true,
                'src' => true,
                'crossorigin' => true,
                'autoplay' => true,
                'playsinline' => true,
                'muted' => true,
                'stream-type' => true,
                'media-type' => true,
                'view-type' => true,
                'load' => true,
                'id' => true,
                'style' => true,
            ],
            'media-provider' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-poster' => [
                'src' => true,
                'alt' => true,
                'load' => true,
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-title' => [
                'class' => true,
                'title' => true,
                'id' => true,
                'style' => true,
            ],
            'media-chapter-title' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-controls' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-controls-group' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-icon' => [
                'class' => true,
                'type' => true,
                'id' => true,
                'style' => true,
                'aria-hidden' => true,
            ],
            'media-menu' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-menu-button' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'aria-label' => true,
            ],
            'media-menu-items' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'placement' => true,
                'offset' => true,
                'aria-label' => true,
            ],
            'track' => [
                'kind' => true,
                'label' => true,
                'src' => true,
                'srclang' => true,
                'default' => true,
                'class' => true,
                'id' => true,
            ],
            'media-gesture' => [
                'event' => true,
                'action' => true,
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-captions' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'aria-hidden' => true,
                'data-dir' => true,
                'translate' => true,
                'aria-live' => true,
                'aria-atomic' => true,
            ],
            'media-tooltip' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-tooltip-trigger' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-tooltip-content' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'placement' => true,
                'role' => true,
            ],
            'media-play-button' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'aria-label' => true,
                'tabindex' => true,
                'role' => true,
                'type' => true,
                'aria-keyshortcuts' => true,
                'aria-pressed' => true,
                'data-describedby' => true,
                'data-pressed' => true,
            ],
            'media-seek-button' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'seconds' => true,
                'aria-label' => true,
                'tabindex' => true,
                'role' => true,
                'type' => true,
                'data-describedby' => true,
                'data-supported' => true,
                'aria-hidden' => true,
            ],
            'media-mute-button' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'aria-label' => true,
                'tabindex' => true,
                'role' => true,
                'type' => true,
                'aria-keyshortcuts' => true,
                'data-state' => true,
                'aria-pressed' => true,
                'data-describedby' => true,
            ],
            'media-pip-button' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'aria-label' => true,
                'tabindex' => true,
                'role' => true,
                'type' => true,
                'aria-keyshortcuts' => true,
                'aria-hidden' => true,
                'aria-pressed' => true,
                'data-describedby' => true,
            ],
            'media-fullscreen-button' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'aria-label' => true,
                'tabindex' => true,
                'role' => true,
                'type' => true,
                'aria-keyshortcuts' => true,
                'data-supported' => true,
                'aria-hidden' => true,
                'aria-pressed' => true,
                'data-describedby' => true,
            ],
            'media-volume-slider' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'aria-label' => true,
                'role' => true,
                'tabindex' => true,
                'autocomplete' => true,
                'aria-disabled' => true,
                'aria-valuemin' => true,
                'aria-valuemax' => true,
                'aria-valuenow' => true,
                'aria-valuetext' => true,
                'aria-orientation' => true,
                'aria-hidden' => true,
                'data-supported' => true,
            ],
            'media-time-slider' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'aria-label' => true,
                'role' => true,
                'tabindex' => true,
                'autocomplete' => true,
                'aria-disabled' => true,
                'aria-valuemin' => true,
                'aria-valuemax' => true,
                'aria-valuenow' => true,
                'aria-valuetext' => true,
                'aria-orientation' => true,
            ],
            'media-time' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'type' => true,
            ],
            'media-slider-chapters' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-slider-preview' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'no-clamp' => true,
            ],
            'media-slider-value' => [
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'media-speed-radio-group' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'value' => true,
            ],
            'media-captions-radio-group' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'value' => true,
            ],
            'media-chapters-radio-group' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'value' => true,
            ],
            'media-slider-thumbnail' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'src' => true,
            ],
            'media-radio' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'value' => true,
                'tabindex' => true,
                'role' => true,
                'aria-checked' => true,
                'data-checked' => true,
            ],
            'template' => [
                'src' => true,
                'class' => true,
                'id' => true,
                'style' => true,
            ],
        ];

        // Add fluentplayer custom elements
        $fluentPlayerTags = [
            'fluentplayer-timestamp' => [
                'time' => true,
                'media_id' => true,
                'class' => true,
                'id' => true,
                'style' => true,
            ],
        ];

        // Merge all tags
        $tags = array_merge($tags, $mediaPlayerTags, $fluentPlayerTags);
        
        // Add common data-* attributes explicitly to all tags
        foreach ($tags as $tag => &$tag_attrs) {
            if (is_array($tag_attrs)) {
                // Add a comprehensive list of data-* attributes for the player
                $data_attrs = [
                    'data-media-player', 'data-media-provider', 'data-media-gesture', 'data-visible',
                    'data-pressed', 'data-describedby', 'data-supported', 'data-state', 'data-checked',
                    'data-root', 'data-submenu', 'data-disabled', 'data-media-tooltip', 'data-media-play-tooltip',
                    'data-media-seek-tooltip', 'data-media-mute-tooltip', 'data-media-pip-tooltip',
                    'data-media-fullscreen-tooltip', 'data-media-mute-button', 'data-media-volume-slider',
                    'data-media-time-slider', 'data-type', 'data-part', 'data-placement', 'data-load',
                    'data-can-seek', 'data-media-type', 'data-paused', 'data-playsinline', 'data-remote-state',
                    'data-remote-type', 'data-stream-type', 'data-view-type', 'data-pointer', 'data-can-fullscreen',
                    'data-can-load-poster', 'data-can-load', 'data-can-play', 'data-playing', 'data-started',
                    'data-orientation', 'data-dir', 'data-no-controls', 'data-var_name', 'data-media-id',
                    'data-layer-id', 'data-action', 'data-hotspot-id', 'data-skip-offset'
                ];
                foreach ($data_attrs as $data_attr) {
                    $tag_attrs[$data_attr] = true;
                }
            }
        }
        unset($tag_attrs);

        // Add SVG support for icons
        $tags['svg'] = [
            'width' => true,
            'height' => true,
            'viewbox' => true,
            'viewBox' => true,
            'fill' => true,
            'xmlns' => true,
            'class' => true,
            'id' => true,
            'style' => true,
            'aria-hidden' => true,
            'role' => true,
            'xmlns:xlink' => true,
        ];
        
        $tags['path'] = [
            'd' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'strokeWidth' => true,
            'stroke-linecap' => true,
            'strokeLinecap' => true,
            'stroke-linejoin' => true,
            'strokeLinejoin' => true,
            'class' => true,
            'id' => true,
            'style' => true,
        ];

        $tags = apply_filters('fluent_player/allowed_html_tags', $tags);

        $html = wp_kses($html, $tags);

        return $html;
    }
}
