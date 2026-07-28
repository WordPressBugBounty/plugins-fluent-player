<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\MediaRenderer;
use FluentPlayer\App\Utils\Enqueuer\Enqueue;

/**
 * Makes a pasted [fluentplayer] / [fluentplaylist] shortcode render inside a builder canvas.
 *
 * Breakdance renders a Shortcode element through a `breakdance_server_side_render` POST and
 * injects the returned HTML with innerHTML. Nothing else of that request survives (no
 * wp_head/wp_footer), so the enqueued runtime and the wp_localize_script config are both
 * discarded, and the canvas page has no player of its own to enqueue them either.
 *
 * The config must be inert: a <script> inserted via innerHTML never executes, which is why
 * MediaRenderer::renderSelfContained()'s inline-script approach (Divi REST previews) is no
 * help here.
 *
 * Shortcode-only by design — no builder element, picker, or settings panel.
 */
class BuilderCanvasHandler
{
    const SHORTCODES = ['fluentplayer', 'fluentmedia'];

    /** @var array<string,bool> Instance var names already emitted this request. */
    protected static $emitted = [];

    public static function register(): void
    {
        // Only a builder canvas needs any of this. Skipping registration otherwise keeps
        // the do_shortcode_tag filter off the site-wide shortcode hot path.
        if (!self::isBuilderCanvasRequest() && !self::isBuilderSsrRequest()) {
            return;
        }

        add_action('wp_enqueue_scripts', [self::class, 'enqueueCanvasRuntime']);
        add_filter('do_shortcode_tag', [self::class, 'attachInlineConfig'], 10, 2);

        // Each element renders in its own request, so the instance counter restarts and
        // repeated placements of one media would collide on the same DOM id.
        if (self::isBuilderSsrRequest()) {
            MediaRenderer::seedInstanceCounter(random_int(1, 1000000));
        }
    }

    /**
     * Detection prefers Breakdance's shipped helpers, falling back to the raw request
     * markers so the logic stays testable without Breakdance installed.
     */
    public static function isBuilderCanvasRequest(): bool
    {
        if (function_exists('Breakdance\isRequestFromBuilderIframe')
            && \Breakdance\isRequestFromBuilderIframe()
        ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context probe
        return !empty($_GET['breakdance_iframe']);
    }

    public static function isBuilderSsrRequest(): bool
    {
        if (function_exists('Breakdance\isRequestFromBuilderSsr')
            && \Breakdance\isRequestFromBuilderSsr()
        ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only context probe
        $action = isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '';

        return $action === 'breakdance_server_side_render';
    }

    /**
     * The playlist needs only this: Pro already emits its config inline and
     * resources/js/fluent-playlist.js already re-scans on injection. Both playlist handles
     * are free assets; the shortcode check keeps a free-only site from loading them.
     */
    public static function enqueueCanvasRuntime(): void
    {
        if (!self::isBuilderCanvasRequest()) {
            return;
        }

        MediaRenderer::enqueuePlayerRuntime();

        if (shortcode_exists('fluentplaylist')) {
            Enqueue::script('fluent_playlist', 'js/fluent-playlist.js', [], FLUENT_PLAYER_VERSION, true);
            Enqueue::style('fluent_playlist_css', 'scss/public/fluent-playlist.scss', [], FLUENT_PLAYER_VERSION);
        }
    }

    /**
     * @param string $output
     * @param string $tag
     * @return string
     */
    public static function attachInlineConfig($output, $tag)
    {
        if (!in_array($tag, self::SHORTCODES, true) || !self::isBuilderSsrRequest()) {
            return $output;
        }

        if (trim((string) $output) === '') {
            return $output;
        }

        return self::pendingConfigBlocks() . $output;
    }

    /**
     * One block per player instance localized so far and not yet emitted. The global
     * `fluent_player` config is skipped — the canvas enqueue already localizes it.
     */
    protected static function pendingConfigBlocks(): string
    {
        $data = wp_scripts()->get_data('fluent_player', 'data');
        if (!is_string($data) || $data === '') {
            return '';
        }

        $blocks = '';

        foreach (explode("\n", $data) as $line) {
            if (!preg_match('/^var (fluent_player_[A-Za-z0-9_]+) = (.+);$/', trim($line), $match)) {
                continue;
            }

            $varName = $match[1];
            if (isset(self::$emitted[$varName])) {
                continue;
            }

            $json = self::escapeForScriptBlock($match[2]);
            if ($json === '') {
                continue;
            }

            self::$emitted[$varName] = true;

            $blocks .= sprintf(
                '<script type="application/json" class="fp-inline-config" data-var-name="%s">%s</script>',
                esc_attr($varName),
                $json
            );
        }

        return $blocks;
    }

    /** JSON_HEX_TAG so a payload containing `</script>` cannot close the block. */
    protected static function escapeForScriptBlock(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }

        $encoded = wp_json_encode($decoded, JSON_HEX_TAG | JSON_HEX_AMP);

        return is_string($encoded) ? $encoded : '';
    }

    /** Test seam: forget which instances were emitted this request. */
    public static function resetEmitted(): void
    {
        self::$emitted = [];
    }
}
