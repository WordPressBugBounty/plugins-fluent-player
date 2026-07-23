<?php

namespace FluentPlayer\App\PageBuilders\Divi;

if (!defined('ABSPATH')) exit;

use ET\Builder\Framework\DependencyManagement\Interfaces\DependencyInterface;
use ET\Builder\Packages\ModuleLibrary\ModuleRegistration;
use FluentPlayer\App\PageBuilders\AbstractPageBuilder;

/**
 * Native Divi 5 module for Fluent Player, registered via the DependencyTree. Its
 * PHP render_callback returns the shared player markup for both the frontend and
 * the Visual Builder. Loaded only under Divi 5 (implements a Divi-5-only interface).
 */
class FluentPlayerModule implements DependencyInterface
{
    // MUST differ from the Gutenberg block name (fluent-player/media): Divi 5 registers
    // the module as a WP block type under this name at theme boot, so reusing the block
    // name would hijack every do_blocks()/shortcode render of the media block site-wide.
    private const MODULE_NAME = 'fluent-player/divi-media';

    public function load(): void
    {
        // register_module ends in register_block_type_from_metadata, so it must run on
        // init. load() itself runs during Divi's dependency-tree flow on init:0, but
        // wrapping in init keeps registration on the documented hook regardless of when
        // load() is invoked.
        add_action('init', function () {
            ModuleRegistration::register_module(
                __DIR__ . '/fluent-player-media',
                [
                    'render_callback' => [self::class, 'render_callback'],
                ]
            );
        });
    }

    /**
     * @param array  $attrs
     * @param string $content
     * @param mixed  $block
     * @param mixed  $elements
     * @return string
     */
    public static function render_callback($attrs, $content, $block, $elements): string
    {
        $mediaId = isset($attrs['content']['innerContent']['desktop']['value'])
            ? absint($attrs['content']['innerContent']['desktop']['value'])
            : 0;

        if (!$mediaId) {
            return AbstractPageBuilder::emptyPlaceholder(
                __('Select a Fluent Player media to display.', 'fluent-player')
            );
        }

        return AbstractPageBuilder::renderPlayer($mediaId);
    }
}
