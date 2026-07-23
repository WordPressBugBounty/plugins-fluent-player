<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

/**
 * Cover the block editor's white boot phase on Fluent Player media/playlist
 * screens with a branded backdrop. The brand is painted *behind* the empty
 * #editor mount point; the moment Gutenberg renders into it, its own opaque
 * chrome hides the backdrop — so no JS is needed to remove anything.
 */
class EditorBootVeilHandler
{
    private $screens = ['fluent_player_media', 'fluent_playlist'];

    public function register()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets()
    {
        if (!$this->onEditorScreen()) {
            return;
        }

        wp_add_inline_style('common', $this->css());
    }

    private function onEditorScreen()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen
            && $screen->base === 'post'
            && in_array($screen->post_type, $this->screens, true);
    }

    private function css()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M28.8 0H3.2C1.43269 0 0 1.43269 0 3.2V28.8C0 30.5673 1.43269 32 3.2 32H28.8C30.5673 32 32 30.5673 32 28.8V3.2C32 1.43269 30.5673 0 28.8 0Z" fill="#DD1F13"/><path d="M19.2289 11.3156L11.9206 7.09616C10.5631 6.31241 8.86624 7.29209 8.86624 8.85959V13.7716C8.86624 15.3391 10.5631 16.3188 11.9206 15.535L19.2289 11.3156ZM22.7029 18.6774C24.7644 17.4872 24.7644 14.5115 22.7028 13.3213C22.488 13.1973 22.2234 13.1973 22.0086 13.3213L10.1126 20.1895C9.34137 20.6348 8.86624 21.4577 8.86624 22.3483C8.86624 24.2673 10.9436 25.4667 12.6054 24.5072L22.7029 18.6774Z" fill="#fff"/></svg>';
        $logo = 'data:image/svg+xml,' . rawurlencode($svg);

        // fpVeilFade is the failsafe: if the editor never mounts (fatal boot
        // error), #editor stays :empty forever — so both layers self-fade after
        // 10s rather than trapping the screen behind a perpetual loader.
        return <<<CSS
/* min-height: #editor is a zero-height sliver until React mounts, so without a
   viewport floor the backdrop would not cover the boot page. */
#editor:empty { display: block; min-height: calc(100vh - 32px); background: #fff; animation: fpVeilFade .4s ease-out 10s forwards; }
#editor:empty::before {
    content: "";
    position: fixed;
    left: 50%;
    top: 50%;
    margin: -24px 0 0 -24px;
    width: 48px;
    height: 48px;
    background: center / contain no-repeat url("{$logo}");
    animation: fpVeilPulse 1.2s ease-in-out infinite, fpVeilFade .4s ease-out 10s forwards;
}
@keyframes fpVeilPulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .55; transform: scale(.94); } }
@keyframes fpVeilFade { to { opacity: 0; visibility: hidden; } }
@media (prefers-reduced-motion: reduce) {
    #editor:empty::before { animation: fpVeilFade .4s ease-out 10s forwards; }
}
CSS;
    }
}
