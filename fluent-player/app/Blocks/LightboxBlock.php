<?php

namespace FluentPlayer\App\Blocks;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Hooks\Handlers\LightboxPreviewHandler;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\SettingsService;
use FluentPlayer\App\Services\UnlockService;
use FluentPlayer\Framework\Support\Arr;

/**
 * `fluent-player/lightbox` — a trigger (button, text link, poster thumbnail or any
 * blocks) that opens a media in the lightbox. The trigger carries
 * data-fluent-player-open, so the Free modal engine opens it; the endpoint that
 * returns the player markup ships with Pro, so without Pro nothing is rendered.
 */
class LightboxBlock
{
    const TRIGGER_TYPES = ['button', 'text', 'thumbnail', 'custom'];
    const BUTTON_STYLES = ['primary', 'outline', 'plain'];
    const SIZES = ['small', 'medium', 'large', 'full'];
    const RATIOS = ['16/9', '4/3', '1/1', '9/16', '21/9'];
    const ALIGNMENTS = ['left', 'center', 'right'];

    /**
     * Register the block type. Its editor script is the shared media block bundle.
     *
     * @return void
     */
    public function register()
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        LightboxPreviewHandler::register();

        register_block_type('fluent-player/lightbox', [
            'api_version'     => 3,
            'editor_script'   => 'fluent-player-block-editor',
            'editor_style'    => 'fluent-player-block-editor-style',
            'render_callback' => [$this, 'render'],
            'attributes'      => self::attributes(),
            'supports'        => [
                'html'    => false,
                'align'   => ['wide', 'full'],
                'spacing' => ['margin' => true],
            ],
        ]);
    }

    /**
     * Attribute schema; mirrored in resources/blocks/lightbox/index.jsx.
     *
     * @return array
     */
    public static function attributes()
    {
        return [
            'mediaId'         => ['type' => 'number'],
            'triggerType'     => ['type' => 'string', 'default' => 'button'],
            'label'           => ['type' => 'string', 'default' => ''],
            'buttonStyle'     => ['type' => 'string', 'default' => 'primary'],
            'showPlayIcon'    => ['type' => 'boolean', 'default' => true],
            'textAlign'       => ['type' => 'string', 'default' => 'left'],
            'imageId'         => ['type' => 'number', 'default' => 0],
            'imageUrl'        => ['type' => 'string', 'default' => ''],
            'aspectRatio'     => ['type' => 'string', 'default' => '16/9'],
            'showCaption'     => ['type' => 'boolean', 'default' => true],
            'showDuration'    => ['type' => 'boolean', 'default' => true],
            'size'            => ['type' => 'string', 'default' => 'medium'],
            'autoplay'        => ['type' => 'boolean', 'default' => true],
            'closeOnBackdrop' => ['type' => 'boolean', 'default' => true],
            'group'           => ['type' => 'string', 'default' => ''],
            'className'       => ['type' => 'string', 'default' => ''],
        ];
    }

    /**
     * Render the trigger.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Rendered inner blocks (the custom trigger).
     * @return string
     */
    public function render($attributes, $content = '')
    {
        $mediaId = absint(Arr::get($attributes, 'mediaId', 0));
        if (!$mediaId || !Helper::hasPro()) {
            return '';
        }

        $media = Media::findVisible($mediaId);
        if (!$media) {
            return '';
        }

        $triggerType = self::pick(Arr::get($attributes, 'triggerType'), self::TRIGGER_TYPES, 'button');
        if ($triggerType === 'custom' && trim((string) $content) === '') {
            return '';
        }

        // Prime the runtime + modal engine (and this media's layer assets) while the
        // page can still print them; the lightbox's AJAX render is too late to enqueue.
        wpfp_enqueue_modal_assets($mediaId);

        $settings = Media::mergePresetSettings((array) $media->settings);
        $title = get_the_title($mediaId);

        $triggerAttrs = [
            'data-fluent-player-open'           => $mediaId,
            'data-fluent-player-title'          => $title,
            'data-fluent-player-size'           => self::pick(Arr::get($attributes, 'size'), self::SIZES, 'medium'),
            'data-fluent-player-autoplay'       => Arr::get($attributes, 'autoplay', true) ? '1' : '0',
            'data-fluent-player-backdrop-close' => Arr::get($attributes, 'closeOnBackdrop', true) ? '1' : '0',
        ];
        $group = self::sanitizeGroup(Arr::get($attributes, 'group', ''));
        if ($group !== '') {
            $triggerAttrs['data-fluent-player-group'] = $group;
        }
        // Private media need the render-issued token so the fetch endpoint returns it.
        if ($media->post_status === 'private') {
            $triggerAttrs['data-access-key'] = UnlockService::issueAccessToken($mediaId);
        }

        /**
         * Filter the data attributes printed on a lightbox trigger (Pro adds its
         * analytics event nonce here). Keys are attribute names; values are escaped
         * on output, and names that aren't `data-*` are dropped.
         *
         * @param array $triggerAttrs
         * @param int   $mediaId
         * @param array $attributes Block attributes.
         */
        $filtered = apply_filters('fluent_player/lightbox_trigger_attrs', $triggerAttrs, $mediaId, $attributes);
        if (is_array($filtered)) {
            foreach ($filtered as $name => $value) {
                if (is_string($name) && preg_match('/^data-[a-z0-9-]+$/', $name) && is_scalar($value)) {
                    $triggerAttrs[$name] = $value;
                }
            }
        }

        switch ($triggerType) {
            case 'thumbnail':
                $trigger = $this->renderThumbnail($attributes, $settings, $title, $triggerAttrs);
                break;
            case 'custom':
                $trigger = $this->renderCustom($content, $title, $triggerAttrs);
                break;
            case 'text':
                $trigger = $this->renderButton('fp-lightbox-link', $attributes, $triggerAttrs);
                break;
            default:
                $style = self::pick(Arr::get($attributes, 'buttonStyle'), self::BUTTON_STYLES, 'primary');
                $classes = 'fp-modal-trigger' . ($style === 'plain' ? '' : ' fp-modal-btn fp-modal-btn--' . $style);
                $trigger = $this->renderButton($classes, $attributes, $triggerAttrs);
        }

        $wrapperClasses = 'fp-lightbox fp-lightbox--' . $triggerType;
        if ($triggerType === 'button' || $triggerType === 'text') {
            $wrapperClasses .= ' has-text-align-' . self::pick(Arr::get($attributes, 'textAlign'), self::ALIGNMENTS, 'left');
        }

        $wrapperArgs = ['class' => $wrapperClasses];
        // The trigger takes the media's own brand / play-button colours.
        $brandVars = Helper::brandingInlineVars($settings, SettingsService::getMediaDefaultSettings($settings));
        if ($brandVars) {
            $wrapperArgs['style'] = $brandVars;
        }

        return '<div ' . get_block_wrapper_attributes($wrapperArgs) . '>' . $trigger . '</div>';
    }

    /**
     * Button or text-link trigger.
     *
     * @param string $classes
     * @param array  $attributes
     * @param array  $triggerAttrs
     * @return string
     */
    private function renderButton($classes, $attributes, $triggerAttrs)
    {
        $label = trim((string) Arr::get($attributes, 'label', ''));
        if ($label === '') {
            $label = __('Watch now', 'fluent-player');
        }

        $icon = Arr::get($attributes, 'showPlayIcon', true) ? self::playIcon() : '';

        return '<button type="button" class="fp-lightbox-trigger ' . esc_attr($classes) . '"' . self::attrs($triggerAttrs) . '>'
            . $icon . '<span>' . esc_html($label) . '</span></button>';
    }

    /**
     * Poster thumbnail trigger: a custom image, else the media poster, with a play disc.
     *
     * @param array  $attributes
     * @param array  $settings
     * @param string $title
     * @param array  $triggerAttrs
     * @return string
     */
    private function renderThumbnail($attributes, $settings, $title, $triggerAttrs)
    {
        $imageUrl = '';
        $imageId = absint(Arr::get($attributes, 'imageId', 0));
        if ($imageId) {
            $imageUrl = (string) wp_get_attachment_image_url($imageId, 'large');
        }
        if ($imageUrl === '') {
            $imageUrl = (string) Arr::get($attributes, 'imageUrl', '');
        }
        if ($imageUrl === '') {
            $imageUrl = (string) Arr::get($settings, 'posterSrc', '');
        }

        $ratio = self::pick(Arr::get($attributes, 'aspectRatio'), self::RATIOS, '16/9');

        $label = trim((string) Arr::get($attributes, 'label', ''));
        $caption = $label !== '' ? $label : $title;
        $showCaption = Arr::get($attributes, 'showCaption', true) && $caption !== '';

        $triggerAttrs['style'] = '--fp-lightbox-ratio:' . str_replace('/', ' / ', $ratio);
        // The visible caption names the button; otherwise say what it plays.
        if (!$showCaption) {
            $triggerAttrs['aria-label'] = $title !== ''
                /* translators: %s: media title. */
                ? sprintf(__('Play %s', 'fluent-player'), $title)
                : __('Play media', 'fluent-player');
        }

        $html = '<button type="button" class="fp-lightbox-trigger fp-lightbox-thumb"' . self::attrs($triggerAttrs) . '>';
        if ($imageUrl !== '') {
            $html .= '<img class="fp-lightbox-thumb__image" src="' . esc_url($imageUrl) . '" alt="" loading="lazy" decoding="async">';
        }
        $html .= '<span class="fp-lightbox-thumb__play" aria-hidden="true">' . self::playIcon() . '</span>';
        if ($showCaption) {
            $html .= '<span class="fp-lightbox-thumb__caption">' . esc_html($caption) . '</span>';
        }
        $duration = Arr::get($attributes, 'showDuration', true) ? self::badgeDuration(Arr::get($settings, 'duration', 0)) : '';
        if ($duration !== '') {
            $html .= '<span class="fp-lightbox-thumb__duration">'
                /* translators: %s: media length, e.g. 4:05. */
                . '<span class="fp-modal-sr-only">' . esc_html(sprintf(__('Duration: %s', 'fluent-player'), $duration)) . '</span>'
                . '<span aria-hidden="true">' . esc_html($duration) . '</span></span>';
        }

        return $html . '</button>';
    }

    /**
     * Any-blocks trigger. Content holding its own link/button already has a keyboard
     * path (its click bubbles to the wrapper); otherwise the wrapper becomes the
     * button, with the engine supplying Enter/Space activation.
     *
     * @param string $content
     * @param string $title
     * @param array  $triggerAttrs
     * @return string
     */
    private function renderCustom($content, $title, $triggerAttrs)
    {
        if (!preg_match('/<(a|button)[\s>]/i', $content)) {
            $triggerAttrs['role'] = 'button';
            $triggerAttrs['tabindex'] = '0';
            if (trim(wp_strip_all_tags($content)) === '') {
                $triggerAttrs['aria-label'] = $title !== ''
                    /* translators: %s: media title. */
                    ? sprintf(__('Play %s', 'fluent-player'), $title)
                    : __('Play media', 'fluent-player');
            }
        }

        // $content is the already-rendered inner blocks markup, same as core/group.
        return '<div class="fp-lightbox-trigger fp-lightbox-custom"' . self::attrs($triggerAttrs) . '>' . $content . '</div>';
    }

    /**
     * Gallery group name: plain text, capped, so it is safe as an attribute value
     * and comparable by value on the front end.
     *
     * @param mixed $group
     * @return string
     */
    public static function sanitizeGroup($group)
    {
        $group = trim(sanitize_text_field((string) $group));
        return function_exists('mb_substr') ? mb_substr($group, 0, 60) : substr($group, 0, 60);
    }

    /**
     * @param array $attrs name => value
     * @return string Escaped ` name="value"` pairs.
     */
    private static function attrs($attrs)
    {
        $html = '';
        foreach ($attrs as $name => $value) {
            $html .= ' ' . $name . '="' . esc_attr((string) $value) . '"';
        }
        return $html;
    }

    /**
     * @param mixed  $value
     * @param array  $allowed
     * @param string $default
     * @return string $value when allowed, else $default.
     */
    private static function pick($value, $allowed, $default)
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Compact duration for the thumbnail badge; mirrors badgeDuration() in
     * resources/blocks/lightbox/utils.js.
     *
     * @param mixed $seconds
     * @return string e.g. "4:05" or "1:02:09"; '' when unknown.
     */
    public static function badgeDuration($seconds)
    {
        $total = is_numeric($seconds) ? (int) floor((float) $seconds) : 0;
        if ($total <= 0) {
            return '';
        }
        $hours = (int) floor($total / 3600);
        $minutes = (int) floor(($total % 3600) / 60);
        $secs = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * @return string Decorative play glyph.
     */
    private static function playIcon()
    {
        return '<svg class="fp-lightbox-play-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11.04-6.86a1 1 0 0 0 0-1.72L9.5 4.28A1 1 0 0 0 8 5.14z"/></svg>';
    }
}
