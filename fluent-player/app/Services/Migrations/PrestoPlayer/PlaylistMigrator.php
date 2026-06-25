<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;

class PlaylistMigrator
{
    /**
     * Migrate Presto Player playlists to Fluent Player
     *
     * Requires: FP Pro (fluent_playlist CPT), media migration completed first
     *
     * @return array
     */
    public static function migrate($force = false)
    {
        $map = get_option(Scanner::MAP_OPTION, []);

        if (!isset($map['playlists'])) {
            $map['playlists'] = [];
        }

        $mediaMap = Arr::get($map, 'media', []);

        if (empty($mediaMap)) {
            return [
                'migrated' => 0,
                'skipped'  => 0,
                'errors'   => [],
                'message'  => __('No media mapping found. Please migrate media first.', 'fluent-player'),
            ];
        }

        $playlists = Scanner::scanPlaylists();

        if (empty($playlists)) {
            return [
                'migrated' => 0,
                'skipped'  => 0,
                'errors'   => [],
                'message'  => __('No playlists found to migrate.', 'fluent-player'),
            ];
        }

        $migrated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($playlists as $playlist) {
            $mapKey = $playlist['post_id'] . '_' . $playlist['index'];

            $existingFpId = !empty($map['playlists'][$mapKey]) ? (int) $map['playlists'][$mapKey] : 0;

            if ($existingFpId && !$force) {
                $skipped++;
                continue;
            }

            try {
                $fpPlaylistId = self::migrateSingle($playlist, $mediaMap, $existingFpId);

                if ($fpPlaylistId > 0) {
                    $map['playlists'][$mapKey] = $fpPlaylistId;
                    $migrated++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'id'    => $playlist['post_id'],
                    'title' => $playlist['heading'],
                    'error' => __('Failed to migrate this playlist.', 'fluent-player'),
                ];
            }
        }

        update_option(Scanner::MAP_OPTION, $map, false);

        return [
            'migrated' => $migrated,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'message'  => sprintf(
                /* translators: 1: number of playlists successfully migrated, 2: number of playlists skipped. */
                __('%1$d playlists migrated, %2$d skipped.', 'fluent-player'),
                $migrated,
                $skipped
            ),
        ];
    }

    /**
     * Migrate a single PP playlist to FP
     *
     * @param array $playlist Scanned playlist data from Scanner::scanPlaylists()
     * @param array $mediaMap PP post_id => FP media_id mapping
     * @return int FP playlist post ID, or 0 if skipped
     */
    private static function migrateSingle($playlist, $mediaMap, $existingFpId = 0)
    {
        $ppItemIds = Arr::get($playlist, 'item_ids', []);
        $attrs = Arr::get($playlist, 'attrs', []);

        $fpMediaIds = [];
        foreach ($ppItemIds as $ppPostId) {
            $fpId = $mediaMap[$ppPostId] ?? null;
            if ($fpId) {
                $fpMediaIds[] = (int) $fpId;
            }
        }

        if (empty($fpMediaIds)) {
            return 0;
        }

        $title = Sanitizer::sanitizeTextField(Arr::get($attrs, 'heading', 'Playlist'));

        $settings = [
            'title'  => $title,
            'medias' => $fpMediaIds,
            'layout' => [
                'type'           => 'standard',
                'position'       => 'right',
                'showHeader'     => true,
                'headerPosition' => 'top',
                'sidebarWidth'   => 40,
            ],
            'appearance' => self::mapAppearance($attrs),
            'behavior'   => [
                'showThumbnails'     => true,
                'thumbnailGridLayout' => false,
                'showThumbnailInfo'  => true,
                'autoplay'           => false,
                'continuousPlay'     => true,
                'loop'               => false,
                'keyboardControls'   => true,
                'touchGestures'      => true,
            ],
        ];

        if ($existingFpId && get_post($existingFpId)) {
            wp_update_post([
                'ID'         => $existingFpId,
                'post_title' => $title,
            ]);
            update_post_meta($existingFpId, 'settings', $settings);
            return $existingFpId;
        }

        $fpPostId = wp_insert_post([
            'post_type'    => 'fluent_playlist',
            'post_title'   => $title,
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:fluent-player/playlist {"playlistId":0} /-->',
        ], true);

        if (is_wp_error($fpPostId)) {
            throw new \Exception(esc_html($fpPostId->get_error_message()));
        }

        wp_update_post([
            'ID'           => $fpPostId,
            'post_content' => '<!-- wp:fluent-player/playlist {"playlistId":' . $fpPostId . '} /-->',
        ]);

        update_post_meta($fpPostId, 'settings', $settings);

        return $fpPostId;
    }

    /**
     * Map PP playlist appearance attrs to FP appearance settings
     *
     * @param array $attrs PP block attributes
     * @return array
     */
    private static function mapAppearance($attrs)
    {
        $appearance = [
            'backgroundColor' => '#ffffff',
            'textColor'       => '#333333',
            'primaryColor'    => '#007bff',
            'borderRadius'    => ['value' => 8, 'unit' => 'px'],
        ];

        $bgColor = Arr::get($attrs, 'color', '');
        if ($bgColor) {
            $appearance['backgroundColor'] = sanitize_hex_color($bgColor) ?: '#ffffff';
        }

        $textColor = Arr::get($attrs, 'textColor', '');
        if ($textColor) {
            $appearance['textColor'] = sanitize_hex_color($textColor) ?: '#333333';
        }

        $highlightColor = Arr::get($attrs, 'highlightColor', '');
        if ($highlightColor) {
            $appearance['primaryColor'] = sanitize_hex_color($highlightColor) ?: '#007bff';
        }

        return $appearance;
    }
}
