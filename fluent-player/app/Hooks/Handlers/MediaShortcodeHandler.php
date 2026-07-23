<?php

namespace FluentPlayer\App\Hooks\Handlers;
if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\DynamicMediaSourceResolver;
use FluentPlayer\App\Services\MediaRenderer;
use FluentPlayer\App\Services\PresetService;
use FluentPlayer\Framework\Support\Arr;

class MediaShortcodeHandler
{
    /**
     * Handle the shortcode.
     *
     * `id` is OPTIONAL. Three render paths:
     * - `id` present            → render that saved media (source_* override its src).
     * - else `video_id` present → behaves like `id` (renders that media; source_*
     *                             override its src) — a clearer-named handle for using
     *                             a media purely as a config/chrome template. With no
     *                             source it renders that media's own video.
     * - else source resolves    → id-less ad-hoc player from the source, configured by
     *                             `preset` (slug/name) or the site default preset.
     * - else                    → empty.
     *
     * Source attributes:
     * - id             (optional) Saved media to render.
     * - video_id       (optional) Media used as a config/chrome template; same render
     *                  behavior as `id` (source_* override its src).
     * - source_url     (optional) Explicit URL.
     * - source_meta    (optional) Meta key read from the post being rendered.
     *                  Power users override the lookup target via the
     *                  `fluent_player/dynamic_source_post_id` filter.
     * - source_poster  (optional) Explicit poster URL.
     *
     * Playback / appearance override attributes (map to saved setting keys; see
     * normalizeOverrides()): preset, autoplay, muted, loop, plays_inline,
     * preload, ratio / aspect_ratio, controls, start, class.
     *
     * Source precedence: source_url → source_meta on current post → saved media.
     * Setting precedence: preset (base) → saved media settings → these attrs.
     *
     * `preset` accepts any resolvable preset slug. The ambient preset is Pro-only;
     * on free, mergePresetSettings() already downgrades it to the minimal preset,
     * so `preset="ambient"` never applies Pro-exclusive behavior on a free site. An
     * unresolvable slug is ignored (the media keeps its own preset).
     *
     * NOTE: whenever any source OR playback override is present, rendering takes
     * the player-only render() path instead of do_blocks(), so timed-content
     * InnerBlocks do NOT render for that embed. A `class`-only shortcode (no other
     * override) keeps the do_blocks() path and still applies the wrapper class.
     *
     * @param array  $atts    Shortcode attributes.
     * @param string $content Shortcode content (unused).
     * @return string HTML output.
     */
    public function handle($atts, $content = '')
    {
        $defaults = [
            'id'            => null,
            'video_id'      => null,
            'source_url'    => '',
            'source_meta'   => '',
            'source_poster' => '',
            // Playback / appearance parity overrides.
            'preset'        => '',
            'autoplay'      => '',
            'muted'         => '',
            'loop'          => '',
            'plays_inline'  => '',
            'preload'       => '',
            'ratio'         => '',
            'aspect_ratio'  => '',
            'controls'      => '',
            'start'         => '',
            'class'         => '',
        ];
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
        $defaults = apply_filters('fluent_player/media_shortcode_defaults', $defaults, $atts);
        $atts = shortcode_atts($defaults, $atts);

        $sourceOverrides = DynamicMediaSourceResolver::resolve(
            esc_url_raw(Arr::get($atts, 'source_url', '')),
            sanitize_text_field(Arr::get($atts, 'source_meta', '')),
            esc_url_raw(Arr::get($atts, 'source_poster', ''))
        ) ?: [];

        $normalized   = self::normalizeOverrides($atts);
        $extraClasses = $normalized['class'];

        // Path 1: explicit `id` — render that saved media (source_* override its src).
        $mediaId = absint(Arr::get($atts, 'id'));
        if ($mediaId) {
            $media = Media::findVisible($mediaId);
            if (!$media) {
                return Media::getAccessDeniedCurtain($mediaId);
            }
            return self::renderSavedMedia($mediaId, $media, $normalized['settings'], $sourceOverrides, $extraClasses);
        }

        // Path 2: `video_id` — a template media supplies the chrome; the video comes
        // from the resolved source. With no source it just renders that media.
        $videoId = absint(Arr::get($atts, 'video_id'));
        if ($videoId) {
            $media = Media::findVisible($videoId);
            if (!$media) {
                return Media::getAccessDeniedCurtain($videoId);
            }
            return self::renderSavedMedia($videoId, $media, $normalized['settings'], $sourceOverrides, $extraClasses);
        }

        // Path 3: id-less — build an ad-hoc player from the resolved source, with the
        // config coming from the preset (or the site default) + inline attrs.
        if ($sourceOverrides) {
            $settings = $normalized['settings'];

            // controls="0" contributes only the three hide flags; resolve the base
            // preset's real behaviors and layer the hide flags on top so sibling
            // flags (plays_inline, muted_autoplay, on_video_end) survive the shallow
            // merge — same guard as the saved-media path in renderSavedMedia().
            if (isset($settings['behaviors'])) {
                $hideFlags = $settings['behaviors'];
                $baseSettings = $settings;
                unset($baseSettings['behaviors']);
                $resolvedBehaviors = (array) Arr::get(Media::mergePresetSettings($baseSettings), 'behaviors', []);
                $settings['behaviors'] = array_merge($resolvedBehaviors, $hideFlags);
            }

            return MediaRenderer::renderFromSource($settings, $sourceOverrides, $extraClasses);
        }

        // Nothing to render (no id, no video_id, no resolvable source).
        return '';
    }

    /**
     * Render a saved media with the parsed setting overrides + resolved source
     * applied. Shared by the `id` and `video_id` paths.
     *
     * @param int    $mediaId
     * @param object $media
     * @param array  $settingOverrides Parsed playback/appearance overrides.
     * @param array  $sourceOverrides  Resolved {src, provider, posterSrc} (or []).
     * @param string $extraClasses
     * @return string
     */
    private static function renderSavedMedia($mediaId, $media, array $settingOverrides, array $sourceOverrides, $extraClasses)
    {
        $overrides = array_merge($settingOverrides, $sourceOverrides);

        // controls="0" contributes a `behaviors` sub-array. render() and
        // mergePresetSettings() only shallow-merge, so a bare replacement would
        // drop sibling behavior flags the media/preset contributes (plays_inline,
        // muted_autoplay, on_video_end). Resolve the media's real behaviors and
        // layer the hide flags on top.
        if (isset($overrides['behaviors'])) {
            $resolvedBehaviors = (array) Arr::get(
                Media::mergePresetSettings((array) $media->settings),
                'behaviors',
                []
            );
            $overrides['behaviors'] = array_merge($resolvedBehaviors, $overrides['behaviors']);
        }

        // No overrides and no wrapper class → saved-media path: do_blocks renders
        // the media block + timed-content InnerBlocks.
        if (!$overrides && $extraClasses === '') {
            return MediaRenderer::renderFromPost($mediaId);
        }

        // renderForPageBuilder: with overrides it uses render() (player only, in-memory
        // overrides applied); with a class but no overrides it keeps do_blocks() and
        // wraps the output in the class — so a class-only embed retains timed content.
        return MediaRenderer::renderForPageBuilder($mediaId, $extraClasses, $overrides);
    }

    /**
     * Map shortcode override attributes to in-memory setting overrides plus a
     * sanitized wrapper class. Pure and side-effect free so it can be unit-tested
     * and reused. Each value is validated / cast; invalid values are dropped so a
     * bad attribute never reaches the settings array.
     *
     * @param array $atts Raw (already shortcode_atts-merged) attributes.
     * @return array{settings: array<string, mixed>, class: string}
     */
    public static function normalizeOverrides(array $atts): array
    {
        $settings = [];

        // Preset by slug or name — only applied when it resolves. An unresolvable
        // ref is dropped rather than written, because mergePresetSettings() treats
        // an unknown preset_slug as "no preset" and would strip the media's own.
        $presetRef = trim((string) Arr::get($atts, 'preset', ''));
        if ($presetRef !== '') {
            $preset = PresetService::resolveRef($presetRef);
            if ($preset) {
                $settings['preset_slug'] = $preset['slug'];
            }
        }

        // Autoplay: "muted" → muted autoplay; any truthy value → autoplay with sound.
        $autoplay = strtolower(trim((string) Arr::get($atts, 'autoplay', '')));
        if ($autoplay === 'muted') {
            $settings['mutedAutoplay'] = true;
        } elseif (self::isTruthy($autoplay)) {
            $settings['autoplay'] = true;
        }

        if (self::isTruthy(Arr::get($atts, 'muted', ''))) {
            $settings['muted'] = true;
        }

        // The view emits the native loop attribute for video_end_option === 'loop'.
        if (self::isTruthy(Arr::get($atts, 'loop', ''))) {
            $settings['video_end_option'] = 'loop';
        }

        if (self::isTruthy(Arr::get($atts, 'plays_inline', ''))) {
            $settings['playsInline'] = true;
        }

        $preload = strtolower(trim((string) Arr::get($atts, 'preload', '')));
        if (in_array($preload, ['none', 'metadata', 'auto'], true)) {
            $settings['preload'] = $preload;
        }

        // `ratio` preferred; `aspect_ratio` is an accepted alias. Both components
        // must be >= 1 so a degenerate ratio (0:0, 4:0) can't become invalid CSS.
        $ratio = trim((string) Arr::get($atts, 'ratio', ''));
        if ($ratio === '') {
            $ratio = trim((string) Arr::get($atts, 'aspect_ratio', ''));
        }
        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $ratio, $rm) && (int) $rm[1] > 0 && (int) $rm[2] > 0) {
            $settings['aspectRatio'] = $ratio;
        }

        // controls="0" (or any falsey) hides all three control bars; "1"/absent
        // leaves the saved/preset control configuration untouched. handle() merges
        // these three flags onto the media's resolved behaviors so sibling flags
        // are preserved.
        $controls = Arr::get($atts, 'controls', '');
        if ($controls !== '' && !self::isTruthy($controls)) {
            $settings['behaviors'] = [
                'hide_top_controls'    => true,
                'hide_center_controls' => true,
                'hide_bottom_controls' => true,
            ];
        }

        // Start offset in whole seconds; must be positive.
        $start = Arr::get($atts, 'start', '');
        if (is_numeric($start) && (int) $start > 0) {
            $settings['startTime'] = absint($start);
        }

        // Support multiple space-separated classes (sanitize each token), since
        // sanitize_html_class() alone would collapse "promo hero" into "promohero".
        $classTokens = preg_split('/\s+/', trim((string) Arr::get($atts, 'class', ''))) ?: [];
        $class = implode(' ', array_filter(array_map('sanitize_html_class', $classTokens)));

        return ['settings' => $settings, 'class' => $class];
    }

    /**
     * Strict truthy parser for shortcode string attributes. Only the common
     * affirmative tokens count as true; everything else (including "0", "no",
     * "false", "") is false.
     *
     * @param mixed $value
     * @return bool
     */
    private static function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
