<?php

namespace FluentPlayer\App\Services\Behavior;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;

/**
 * Resolves the "By media tags" scope: which media carry the selected
 * Fluent Player tags. The taxonomy is registered by Pro, so every lookup
 * fails closed (matches nothing) when it is missing.
 */
class TagScope
{
    const TAXONOMY = 'flp_media_tag';
    const MATCH_ANY = 'any';
    const MATCH_ALL = 'all';

    protected static $mediaCache = [];

    /**
     * @return bool
     */
    public static function available()
    {
        return taxonomy_exists(self::TAXONOMY);
    }

    public static function resetCache()
    {
        self::$mediaCache = [];
    }

    /**
     * Keeps ids of deleted tags so the picker can show them as "(deleted)".
     *
     * @param mixed $raw
     * @return int[]
     */
    public static function sanitizeIds($raw)
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * @param mixed $raw
     * @return string
     */
    public static function sanitizeMatch($raw)
    {
        return self::MATCH_ALL === $raw ? self::MATCH_ALL : self::MATCH_ANY;
    }

    /**
     * @param mixed $ids
     * @return int[] ids that still exist as tags
     */
    public static function validIds($ids)
    {
        $ids = self::sanitizeIds($ids);
        if (!$ids || !self::available()) {
            return [];
        }

        $found = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'include'    => $ids,
            'hide_empty' => false,
            'fields'     => 'ids',
        ]);
        if (is_wp_error($found)) {
            return [];
        }

        $found = array_map('intval', $found);
        return array_values(array_filter($ids, function ($id) use ($found) {
            return in_array($id, $found, true);
        }));
    }

    /**
     * Ids that make up the rule. Empty means the rule matches nothing,
     * including "all" when one of its tags was deleted.
     *
     * @param mixed $ids
     * @param string $match
     * @return int[]
     */
    protected static function effectiveIds($ids, $match)
    {
        $ids = self::sanitizeIds($ids);
        $valid = self::validIds($ids);
        if (self::MATCH_ALL === $match && count($valid) !== count($ids)) {
            return [];
        }
        return $valid;
    }

    /**
     * Media in scope of a tag rule, memoized per request.
     *
     * @param mixed $ids
     * @param string $match
     * @return int[]
     */
    public static function mediaIdsFor($ids, $match = self::MATCH_ANY)
    {
        $match = self::sanitizeMatch($match);
        $ids = self::effectiveIds($ids, $match);
        if (!$ids) {
            return [];
        }

        sort($ids);
        $key = $match . ':' . implode(',', $ids);
        if (isset(self::$mediaCache[$key])) {
            return self::$mediaCache[$key];
        }

        $posts = get_posts([
            'post_type'        => Media::$postType,
            'post_status'      => ['publish', 'draft', 'pending', 'private'],
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'tax_query'        => [[ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'taxonomy'         => self::TAXONOMY,
                'field'            => 'term_id',
                'terms'            => $ids,
                'operator'         => self::MATCH_ALL === $match ? 'AND' : 'IN',
                'include_children' => false,
            ]],
        ]);

        return self::$mediaCache[$key] = array_values(array_map('intval', (array) $posts));
    }

    /**
     * Event-time membership check against the media's current tags.
     *
     * @param int $mediaId
     * @param mixed $ids
     * @param string $match
     * @return bool
     */
    public static function mediaMatches($mediaId, $ids, $match = self::MATCH_ANY)
    {
        $mediaId = (int) $mediaId;
        $match = self::sanitizeMatch($match);
        $ids = self::effectiveIds($ids, $match);
        if ($mediaId <= 0 || !$ids) {
            return false;
        }

        // has_term(), not is_object_in_term(): the latter returns a truthy WP_Error
        if (self::MATCH_ALL === $match) {
            foreach ($ids as $id) {
                if (!has_term($id, self::TAXONOMY, $mediaId)) {
                    return false;
                }
            }
            return true;
        }

        return (bool) has_term($ids, self::TAXONOMY, $mediaId);
    }

    /**
     * @param string $search
     * @return array<int, array{value: int, label: string}>
     */
    public static function pickerOptions($search = '')
    {
        if (!self::available()) {
            return [];
        }

        $args = [
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];
        if ('' !== (string) $search) {
            $args['search'] = (string) $search;
        }

        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return [];
        }

        $options = [];
        foreach ($terms as $term) {
            $options[] = ['value' => (int) $term->term_id, 'label' => self::termLabel($term)];
        }
        return $options;
    }

    /**
     * Labels for saved ids; deleted tags (or a missing taxonomy) stay listed.
     *
     * @param mixed $ids
     * @return array<int, array{value: int, label: string}>
     */
    public static function selectedOptions($ids)
    {
        $options = [];
        foreach (self::sanitizeIds($ids) as $id) {
            $term = self::available() ? get_term($id, self::TAXONOMY) : null;
            $options[] = [
                'value' => $id,
                'label' => ($term && !is_wp_error($term))
                    ? self::termLabel($term)
                    /* translators: %d: id of a media tag that no longer exists */
                    : sprintf(__('#%d (deleted)', 'fluent-player'), $id),
            ];
        }
        return $options;
    }

    protected static function termLabel($term)
    {
        return html_entity_decode((string) $term->name, ENT_QUOTES | ENT_HTML5);
    }
}
