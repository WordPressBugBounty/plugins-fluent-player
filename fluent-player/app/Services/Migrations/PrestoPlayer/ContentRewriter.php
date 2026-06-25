<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class ContentRewriter
{
    /**
     * Block types that can be rewritten to FP
     */
    private static $rewritableBlocks = [
        'presto-player/self-hosted',
        'presto-player/youtube',
        'presto-player/vimeo',
        'presto-player/audio',
        'presto-player/bunny',
        'presto-player/reusable',
        'presto-player/reusable-display',
    ];

    /**
     * Block types that cannot be migrated (logged as warnings)
     */
    private static $unsupportedBlocks = [
        'presto-player/popup',
        'presto-player/popup-trigger',
        'presto-player/popup-media',
        'presto-player/media-hub',
    ];

    /**
     * Rewrite Presto Player blocks and shortcodes in post content
     *
     * @param array $postIds Post IDs to rewrite
     * @return array Results
     */
    public static function rewrite($postIds)
    {
        $map = get_option(Scanner::MAP_OPTION, []);
        $mediaMap = Arr::get($map, 'media', []);

        if (empty($mediaMap)) {
            return [
                'rewritten' => 0,
                'errors'    => [],
                'message'   => __('No media mapping found. Please migrate media first.', 'fluent-player'),
            ];
        }

        $videoIdToPostId = self::buildVideoIdLookup();
        _prime_post_caches($postIds, true, false);

        $rewritten = 0;
        $skippedBlocks = 0;
        $errors = [];

        foreach ($postIds as $postId) {
            $postId = (int) $postId;

            try {
                $result = self::rewritePost($postId, $mediaMap, $map, $videoIdToPostId);

                if ($result['changed']) {
                    $rewritten++;
                }
                $skippedBlocks += $result['skipped_blocks'];
            } catch (\Exception $e) {
                $post = get_post($postId);
                $errors[] = [
                    'id'    => $postId,
                    'title' => $post ? Sanitizer::sanitizeTextField($post->post_title) : "(ID: {$postId})",
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($rewritten > 0) {
            $map = get_option(Scanner::MAP_OPTION, []);
            $map['content_rewrite'] = ($map['content_rewrite'] ?? 0) + $rewritten;
            update_option(Scanner::MAP_OPTION, $map, false);
        }

        return [
            'rewritten'      => $rewritten,
            'skipped_blocks' => $skippedBlocks,
            'errors'         => $errors,
            'message'        => sprintf(
                /* translators: %d: number of posts whose content was rewritten. */
                __('%d posts rewritten.', 'fluent-player'),
                $rewritten
            ),
        ];
    }

    /**
     * Rewrite a single post's content
     *
     * @param int $postId
     * @param array $mediaMap PP post_id → FP media_id
     * @param array $map Full migration map
     * @param array $videoIdToPostId Pre-loaded video_id → post_id lookup
     * @return array [changed => bool, skipped_blocks => int]
     */
    private static function rewritePost($postId, $mediaMap, $map = [], $videoIdToPostId = [])
    {
        $post = get_post($postId);

        if (!$post) {
            throw new \Exception(esc_html__('Post not found.', 'fluent-player'));
        }

        $content = $post->post_content;
        $changed = false;
        $skippedBlocks = 0;

        $playlistIndex = 0;
        $blocks = parse_blocks($content);
        $newBlocks = self::rewriteBlocks($blocks, $mediaMap, $changed, $skippedBlocks, $map, $postId, $playlistIndex, $videoIdToPostId);

        if ($changed) {
            $content = serialize_blocks($newBlocks);
        }

        $shortcodeResult = self::rewriteShortcodes($content, $mediaMap, $map);
        if ($shortcodeResult['changed']) {
            $content = $shortcodeResult['content'];
            $changed = true;
        }

        if ($changed) {
            $result = wp_update_post([
                'ID'           => $postId,
                'post_content' => $content,
            ], true);

            if (is_wp_error($result)) {
                throw new \Exception(esc_html($result->get_error_message()));
            }
        }

        return [
            'changed'        => $changed,
            'skipped_blocks' => $skippedBlocks,
        ];
    }

    /**
     * Recursively rewrite blocks in the block tree
     *
     * @param array $blocks Parsed blocks
     * @param array $mediaMap
     * @param bool &$changed Modified flag
     * @param int &$skippedBlocks Counter for unsupported blocks
     * @param array $map Full migration map
     * @param int $postId Current post ID being rewritten
     * @param int &$playlistIndex Counter for playlist blocks within this post
     * @return array Modified blocks
     */
    private static function rewriteBlocks($blocks, $mediaMap, &$changed, &$skippedBlocks, $map = [], $postId = 0, &$playlistIndex = 0, $videoIdToPostId = [])
    {
        $newBlocks = [];
        $hasPro = \FluentPlayer\App\Helpers\Helper::hasPro();

        foreach ($blocks as $block) {
            $blockName = Arr::get($block, 'blockName', '');

            if ($blockName === 'presto-player/playlist') {
                if ($hasPro) {
                    $fpBlock = self::convertPlaylistBlock($block, $map, $postId, $playlistIndex);
                    $playlistIndex++;
                    if ($fpBlock) {
                        ContentReverter::saveOriginalBlock($postId, Arr::get($fpBlock, 'attrs.playlistId', 0), $block);
                        $newBlocks[] = $fpBlock;
                        $changed = true;
                        continue;
                    }
                }
                $playlistIndex++;
                $newBlocks[] = $block;
                $skippedBlocks++;
                continue;
            }

            if (in_array($blockName, self::$rewritableBlocks, true)) {
                $fpBlock = self::convertBlock($block, $mediaMap, $videoIdToPostId);

                if ($fpBlock) {
                    $newBlocks[] = $fpBlock;
                    $changed = true;
                } else {
                    $newBlocks[] = $block;
                    $skippedBlocks++;
                }
                continue;
            }

            if (in_array($blockName, self::$unsupportedBlocks, true)) {
                $newBlocks[] = $block;
                $skippedBlocks++;
                continue;
            }

            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = self::rewriteBlocks(
                    $block['innerBlocks'],
                    $mediaMap,
                    $changed,
                    $skippedBlocks,
                    $map,
                    $postId,
                    $playlistIndex,
                    $videoIdToPostId
                );
            }

            $newBlocks[] = $block;
        }

        return $newBlocks;
    }

    /**
     * Convert a Presto Player block to a Fluent Player block
     *
     * @param array $block Parsed block
     * @param array $mediaMap
     * @param array $videoIdToPostId
     * @return array|null FP block or null if mapping not found
     */
    private static function convertBlock($block, $mediaMap, $videoIdToPostId = [])
    {
        $blockName = Arr::get($block, 'blockName', '');
        $attrs = Arr::get($block, 'attrs', []);

        if ($blockName === 'presto-player/reusable' || $blockName === 'presto-player/reusable-display') {
            $ppPostId = Arr::get($attrs, 'id', 0);
        } else {
            $ppVideoId = absint(Arr::get($attrs, 'id', 0));
            $ppPostId = $ppVideoId ? ($videoIdToPostId[$ppVideoId] ?? null) : null;
        }

        if (!$ppPostId || !isset($mediaMap[$ppPostId])) {
            return null;
        }

        $fpMediaId = (int) $mediaMap[$ppPostId];

        return [
            'blockName'    => 'fluent-player/media',
            'attrs'        => ['mediaId' => $fpMediaId],
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ];
    }

    /**
     * Convert a Presto Player playlist block to a Fluent Player playlist block
     *
     * @param array $block Parsed playlist block
     * @param array $map Full migration map
     * @param int $postId The post containing this playlist block
     * @param int $playlistIndex The index of this playlist block within the post
     * @return array|null FP block or null if no mapping found
     */
    private static function convertPlaylistBlock($block, $map, $postId, $playlistIndex)
    {
        $playlistMap = Arr::get($map, 'playlists', []);

        if (empty($playlistMap)) {
            return null;
        }

        $mapKey = $postId . '_' . $playlistIndex;
        $fpPlaylistId = (int) Arr::get($playlistMap, $mapKey, 0);

        if (!$fpPlaylistId) {
            return null;
        }

        return [
            'blockName'    => 'fluent-player/playlist',
            'attrs'        => ['playlistId' => $fpPlaylistId],
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ];
    }

    /**
     * Build a video_id → post_id lookup from presto_player_videos table.
     *
     * @return array video_id => post_id
     */
    private static function buildVideoIdLookup()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'presto_player_videos';
        if (!Scanner::tableExists($table)) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration lookup: $table from $wpdb->prefix + literal suffix; one-shot read of Presto videos table; caching not appropriate.
        $rows = $wpdb->get_results("SELECT id, post_id FROM `{$table}`");

        $lookup = [];
        foreach ($rows as $row) {
            $lookup[(int) $row->id] = (int) $row->post_id;
        }

        return $lookup;
    }

    /**
     * Rewrite Presto Player shortcodes in content
     *
     * @param string $content
     * @param array $mediaMap
     * @param array $map Full migration map
     * @return array [content => string, changed => bool]
     */
    private static function rewriteShortcodes($content, $mediaMap, $map = [])
    {
        $changed = false;

        $pattern = '/\[presto_player\s+([^\]]*)\]/i';

        $newContent = preg_replace_callback($pattern, function ($matches) use ($mediaMap, &$changed) {
            $attrString = $matches[1];

            if (preg_match('/id\s*=\s*["\']?(\d+)["\']?/', $attrString, $idMatch)) {
                $ppPostId = (int) $idMatch[1];

                if (isset($mediaMap[$ppPostId])) {
                    $fpMediaId = (int) $mediaMap[$ppPostId];
                    $changed = true;
                    return '[fluentplayer id="' . $fpMediaId . '"]';
                }
            }

            return $matches[0];
        }, $content);

        $newContent = preg_replace_callback(
            '/\[(presto_timestamp|pptime)\s+([^\]]*)\](.*?)\[\/\1\]/is',
            function ($matches) use ($mediaMap, &$changed) {
                $attrString = $matches[2];
                $innerText = $matches[3];

                if (preg_match('/media_id\s*=\s*["\']?(\d+)["\']?/', $attrString, $idMatch)) {
                    $ppPostId = (int) $idMatch[1];
                    if (isset($mediaMap[$ppPostId])) {
                        $fpMediaId = (int) $mediaMap[$ppPostId];
                        $time = '0:00';
                        if (preg_match('/time\s*=\s*["\']?(\d+(?::\d{2}){0,2})["\']?/', $attrString, $timeMatch)) {
                            $time = $timeMatch[1];
                        }
                        $changed = true;
                        return '[fluentplayer_timestamp media_id="' . $fpMediaId . '" time="' . Sanitizer::escAttr($time) . '"]' . $innerText . '[/fluentplayer_timestamp]';
                    }
                }

                return $matches[0];
            },
            $newContent
        );

        if (\FluentPlayer\App\Helpers\Helper::hasPro()) {
            $playlistMap = Arr::get($map, 'playlists', []);

            if (!empty($playlistMap)) {
                $playlistIds = array_filter(array_map('absint', array_values($playlistMap)));
                if (!empty($playlistIds)) {
                    update_meta_cache('post', $playlistIds);
                }
                $newContent = preg_replace_callback(
                    '/\[presto_playlist\s*([^\]]*)\].*?\[\/presto_playlist\]/is',
                    function ($matches) use ($playlistMap, $mediaMap, &$changed) {
                        preg_match_all('/\[presto_playlist_item\s+[^\]]*id\s*=\s*["\']?(\d+)["\']?/', $matches[0], $itemMatches);
                        $ppItemIds = array_map('intval', $itemMatches[1] ?? []);

                        foreach ($playlistMap as $mapKey => $fpPlaylistId) {
                            $fpPlaylistId = (int) $fpPlaylistId;
                            if ($fpPlaylistId > 0) {
                                $fpSettings = get_post_meta($fpPlaylistId, 'settings', true);
                                $fpMediaIds = Arr::get($fpSettings, 'medias', []);

                                $mappedIds = [];
                                foreach ($ppItemIds as $ppId) {
                                    if (isset($mediaMap[$ppId])) {
                                        $mappedIds[] = (int) $mediaMap[$ppId];
                                    }
                                }

                                if (!empty($mappedIds) && !array_diff($mappedIds, $fpMediaIds)) {
                                    $changed = true;
                                    return '[fluentplaylist id="' . $fpPlaylistId . '"]';
                                }
                            }
                        }

                        return $matches[0];
                    },
                    $newContent
                );
            }
        }

        return [
            'content' => $newContent,
            'changed' => $changed,
        ];
    }
}
