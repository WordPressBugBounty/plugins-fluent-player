<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class ContentReverter
{
    /**
     * Revert content rewrites: FP blocks/shortcodes back to PP originals
     *
     * @param array $map Full migration map (needed before it's deleted)
     * @return array [reverted => int, errors => array]
     */
    public static function revert($map)
    {
        $mediaMap = Arr::get($map, 'media', []);
        $playlistMap = Arr::get($map, 'playlists', []);

        if (empty($mediaMap) && empty($playlistMap)) {
            return ['reverted' => 0, 'errors' => []];
        }

        $reverseMediaMap = [];
        foreach ($mediaMap as $ppPostId => $fpMediaId) {
            $reverseMediaMap[(int) $fpMediaId] = (int) $ppPostId;
        }

        $reversePlaylistMap = [];
        foreach ($playlistMap as $mapKey => $fpPlaylistId) {
            $reversePlaylistMap[(int) $fpPlaylistId] = $mapKey;
        }

        global $wpdb;

        $reverted = 0;
        $errors = [];
        $lastId = 0;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration code: $wpdb->posts table is core; $lastId is int; LIKE patterns are hardcoded block/shortcode markers (no user input); one-shot read of post batch during migration; caching not appropriate.
        do {
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content FROM `{$wpdb->posts}`
                 WHERE post_type NOT IN ('fluent_player_media', 'fluent_playlist', 'revision', 'nav_menu_item')
                 AND post_status IN ('publish', 'draft', 'private', 'pending')
                 AND ID > %d
                 AND (
                     post_content LIKE '%%<!-- wp:fluent-player/media%%'
                     OR post_content LIKE '%%<!-- wp:fluent-player/playlist%%'
                     OR post_content LIKE '%%[fluentplayer %%'
                     OR post_content LIKE '%%[fluentplayer_timestamp %%'
                     OR post_content LIKE '%%[fluentplaylist %%'
                 )
                 ORDER BY ID ASC
                 LIMIT 200",
                $lastId
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

            foreach ($posts as $post) {
                $lastId = (int) $post->ID;

                try {
                    $result = self::revertPost($post, $reverseMediaMap, $reversePlaylistMap);
                    if ($result) {
                        $reverted++;
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'id'    => $post->ID,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        } while (!empty($posts));

        return ['reverted' => $reverted, 'errors' => $errors];
    }

    /**
     * Revert a single post's content from FP back to PP
     *
     * @param object $post
     * @param array $reverseMediaMap FP media_id → PP post_id
     * @param array $reversePlaylistMap FP playlist_id → PP map_key
     * @return bool Whether content was changed
     */
    private static function revertPost($post, $reverseMediaMap, $reversePlaylistMap)
    {
        $content = $post->post_content;
        $changed = false;

        $blocks = parse_blocks($content);
        $newBlocks = self::revertBlocks($blocks, $reverseMediaMap, $reversePlaylistMap, $changed, $post->ID);

        if ($changed) {
            $content = serialize_blocks($newBlocks);
        }

        $content = self::revertShortcodes($content, $reverseMediaMap, $reversePlaylistMap, $changed);

        if ($changed) {
            $result = wp_update_post([
                'ID'           => $post->ID,
                'post_content' => $content,
            ], true);

            if (is_wp_error($result)) {
                throw new \Exception(esc_html($result->get_error_message()));
            }

            delete_post_meta($post->ID, '_fp_migration_original_blocks');
        }

        return $changed;
    }

    /**
     * Revert FP shortcodes back to PP shortcodes
     *
     * @param string $content
     * @param array $reverseMediaMap
     * @param array $reversePlaylistMap
     * @param bool &$changed
     * @return string
     */
    private static function revertShortcodes($content, $reverseMediaMap, $reversePlaylistMap, &$changed)
    {
        $content = preg_replace_callback(
            '/\[fluentplayer\s+id\s*=\s*["\']?(\d+)["\']?\s*\]/i',
            function ($matches) use ($reverseMediaMap, &$changed) {
                $fpMediaId = (int) $matches[1];
                if (isset($reverseMediaMap[$fpMediaId])) {
                    $changed = true;
                    return '[presto_player id="' . $reverseMediaMap[$fpMediaId] . '"]';
                }
                return $matches[0];
            },
            $content
        );

        $content = preg_replace_callback(
            '/\[fluentplayer_timestamp\s+([^\]]*)\](.*?)\[\/fluentplayer_timestamp\]/is',
            function ($matches) use ($reverseMediaMap, &$changed) {
                $attrString = $matches[1];
                $innerText = $matches[2];

                if (preg_match('/media_id\s*=\s*["\']?(\d+)["\']?/', $attrString, $idMatch)) {
                    $fpMediaId = (int) $idMatch[1];
                    if (isset($reverseMediaMap[$fpMediaId])) {
                        $time = '0:00';
                        if (preg_match('/time\s*=\s*["\']?([^"\']+)["\']?/', $attrString, $timeMatch)) {
                            $time = $timeMatch[1];
                        }
                        $changed = true;
                        return '[presto_timestamp media_id="' . $reverseMediaMap[$fpMediaId] . '" time="' . Sanitizer::escAttr($time) . '"]' . $innerText . '[/presto_timestamp]';
                    }
                }
                return $matches[0];
            },
            $content
        );

        $content = preg_replace_callback(
            '/\[fluentplaylist\s+id\s*=\s*["\']?(\d+)["\']?\s*\]/i',
            function ($matches) use ($reversePlaylistMap, $reverseMediaMap, &$changed) {
                $fpPlaylistId = (int) $matches[1];
                if (isset($reversePlaylistMap[$fpPlaylistId])) {
                    $settings = get_post_meta($fpPlaylistId, 'settings', true);
                    $fpMediaIds = is_array($settings) ? Arr::get($settings, 'medias', []) : [];
                    $heading = is_array($settings) ? Arr::get($settings, 'title', 'Playlist') : 'Playlist';

                    $items = [];
                    foreach ($fpMediaIds as $fpMediaId) {
                        $ppPostId = $reverseMediaMap[(int) $fpMediaId] ?? null;
                        if ($ppPostId) {
                            $items[] = '[presto_playlist_item id="' . (int) $ppPostId . '"]';
                        }
                    }

                    if (!empty($items)) {
                        $changed = true;
                        return '[presto_playlist heading="' . Sanitizer::escAttr($heading) . '"]' . implode('', $items) . '[/presto_playlist]';
                    }
                    return $matches[0];
                }
                return $matches[0];
            },
            $content
        );

        return $content;
    }

    /**
     * Revert FP blocks back to PP blocks
     *
     * @param array $blocks
     * @param array $reverseMediaMap
     * @param array $reversePlaylistMap
     * @param bool &$changed
     * @param int $postId
     * @return array
     */
    private static function revertBlocks($blocks, $reverseMediaMap, $reversePlaylistMap, &$changed, $postId = 0)
    {
        $newBlocks = [];

        foreach ($blocks as $block) {
            $blockName = Arr::get($block, 'blockName', '');

            if ($blockName === 'fluent-player/media') {
                $fpMediaId = (int) Arr::get($block, 'attrs.mediaId', 0);
                if (isset($reverseMediaMap[$fpMediaId])) {
                    $ppPostId = $reverseMediaMap[$fpMediaId];
                    $newBlocks[] = [
                        'blockName'    => 'presto-player/reusable-display',
                        'attrs'        => ['id' => $ppPostId],
                        'innerBlocks'  => [],
                        'innerHTML'    => '<div class="wp-block-presto-player-reusable-display"></div>',
                        'innerContent' => ['<div class="wp-block-presto-player-reusable-display"></div>'],
                    ];
                    $changed = true;
                    continue;
                }
            }

            if ($blockName === 'fluent-player/playlist') {
                $fpPlaylistId = (int) Arr::get($block, 'attrs.playlistId', 0);
                if (isset($reversePlaylistMap[$fpPlaylistId])) {
                    $originalBlock = $postId ? self::getOriginalBlock($postId, $fpPlaylistId) : null;
                    if ($originalBlock) {
                        $newBlocks[] = $originalBlock;
                        $changed = true;
                    } else {
                        $reconstructed = self::reconstructPpPlaylistBlock($fpPlaylistId, $reverseMediaMap);
                        if ($reconstructed) {
                            $newBlocks[] = $reconstructed;
                            $changed = true;
                        } else {
                            $newBlocks[] = $block;
                        }
                    }
                    continue;
                }
            }

            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = self::revertBlocks(
                    $block['innerBlocks'],
                    $reverseMediaMap,
                    $reversePlaylistMap,
                    $changed,
                    $postId
                );
            }

            $newBlocks[] = $block;
        }

        return $newBlocks;
    }

    /**
     * Reconstruct a PP playlist block from FP playlist settings when original was not saved.
     *
     * @param int $fpPlaylistId
     * @param array $reverseMediaMap FP media_id => PP post_id
     * @return array|null Reconstructed parsed block
     */
    private static function reconstructPpPlaylistBlock($fpPlaylistId, $reverseMediaMap)
    {
        $settings = get_post_meta($fpPlaylistId, 'settings', true);

        if (empty($settings) || !is_array($settings)) {
            return null;
        }

        $fpMediaIds = Arr::get($settings, 'medias', []);
        $heading = Arr::get($settings, 'title', 'Playlist');

        $innerBlocks = [];
        $innerHtmlParts = [];

        foreach ($fpMediaIds as $fpMediaId) {
            $ppPostId = $reverseMediaMap[(int) $fpMediaId] ?? null;
            if (!$ppPostId) {
                continue;
            }

            $innerBlocks[] = [
                'blockName'    => 'presto-player/reusable-display',
                'attrs'        => ['id' => (int) $ppPostId],
                'innerBlocks'  => [],
                'innerHTML'    => '<div class="wp-block-presto-player-reusable-display"></div>',
                'innerContent' => ['<div class="wp-block-presto-player-reusable-display"></div>'],
            ];
            $innerHtmlParts[] = '<div class="wp-block-presto-player-reusable-display"></div>';
        }

        if (empty($innerBlocks)) {
            return null;
        }

        $innerHTML = '<div class="wp-block-presto-player-playlist">' . implode('', $innerHtmlParts) . '</div>';

        return [
            'blockName'    => 'presto-player/playlist',
            'attrs'        => ['heading' => Sanitizer::sanitizeTextField($heading)],
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $innerHTML,
            'innerContent' => [$innerHTML],
        ];
    }

    /**
     * Save original PP block data so it can be restored during revert.
     *
     * @param int $postId The post containing the block
     * @param int $fpId The FP playlist ID used as the block replacement
     * @param array $originalBlock The original parsed PP block
     */
    public static function saveOriginalBlock($postId, $fpId, $originalBlock)
    {
        if (!$fpId) {
            return;
        }

        $saved = get_post_meta($postId, '_fp_migration_original_blocks', true) ?: [];
        $saved['playlist_' . $fpId] = serialize_blocks([$originalBlock]);
        update_post_meta($postId, '_fp_migration_original_blocks', $saved);
    }

    /**
     * Retrieve saved original PP block markup for a given FP playlist ID.
     *
     * @param int $postId
     * @param int $fpPlaylistId
     * @return array|null Parsed block or null
     */
    private static function getOriginalBlock($postId, $fpPlaylistId)
    {
        $saved = get_post_meta($postId, '_fp_migration_original_blocks', true);

        if (empty($saved) || !isset($saved['playlist_' . $fpPlaylistId])) {
            return null;
        }

        $blocks = parse_blocks($saved['playlist_' . $fpPlaylistId]);

        return $blocks[0] ?? null;
    }
}
