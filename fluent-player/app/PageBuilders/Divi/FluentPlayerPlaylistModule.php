<?php

namespace FluentPlayer\App\PageBuilders\Divi;

if (!defined('ABSPATH')) exit;

use ET\Builder\Framework\DependencyManagement\Interfaces\DependencyInterface;
use ET\Builder\Packages\ModuleLibrary\ModuleRegistration;
use FluentPlayer\App\PageBuilders\AbstractPageBuilder;

/**
 * Native Divi 5 module for a Fluent Player playlist. Its render_callback returns the
 * shared playlist markup (the fluent-player/playlist block via do_blocks — Pro renders
 * it) for both the frontend and the Visual Builder. Loaded only under Divi 5 (implements
 * a Divi-5-only interface). Parallels FluentPlayerModule (the media module).
 */
class FluentPlayerPlaylistModule implements DependencyInterface
{
    // MUST differ from the Gutenberg block name (fluent-player/playlist) AND the media
    // module: Divi 5 registers the module as a WP block type under this name at theme
    // boot, so reusing the block name would hijack every do_blocks()/shortcode render of
    // the playlist site-wide.
    private const MODULE_NAME = 'fluent-player/divi-playlist';

    public function load(): void
    {
        // register_module ends in register_block_type_from_metadata, so it must run on
        // init. load() itself runs during Divi's dependency-tree flow on init:0, but
        // wrapping in init keeps registration on the documented hook regardless of when
        // load() is invoked.
        add_action('init', function () {
            ModuleRegistration::register_module(
                __DIR__ . '/fluent-player-playlist',
                [
                    'render_callback' => [self::class, 'render_callback'],
                ]
            );
        });
    }

    public static function render_callback($attrs, $content, $block, $elements): string
    {
        $sourceType = isset($attrs['content']['sourceType']['desktop']['value'])
            ? sanitize_key($attrs['content']['sourceType']['desktop']['value'])
            : 'playlist';

        if ($sourceType === 'tags') {
            $tagsRaw = $attrs['content']['tags']['desktop']['value'] ?? '';
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) $tagsRaw))));
            if (empty($tags)) {
                return AbstractPageBuilder::emptyPlaceholder(
                    __('Select at least one tag to display a playlist by tags.', 'fluent-player')
                );
            }

            $query = $attrs['content']['query']['desktop']['value'] ?? [];
            if (is_string($query)) {
                $decoded = json_decode($query, true);
                $query = is_array($decoded) ? $decoded : [];
            }

            return AbstractPageBuilder::renderPlaylist(0, [
                'sourceType' => 'tags',
                'tags'       => $tags,
                'query'      => is_array($query) ? $query : [],
            ]);
        }

        $playlistId = isset($attrs['content']['innerContent']['desktop']['value'])
            ? absint($attrs['content']['innerContent']['desktop']['value'])
            : 0;

        if (!$playlistId) {
            return AbstractPageBuilder::emptyPlaceholder(
                __('Select a Fluent Player playlist to display.', 'fluent-player')
            );
        }

        return AbstractPageBuilder::renderPlaylist($playlistId);
    }
}
