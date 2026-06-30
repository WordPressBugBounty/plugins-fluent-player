<?php

namespace FluentPlayer\App\Helpers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Sanitizer;

/**
 * Shared plumbing for bulk-action endpoints (Free and Pro): id sanitization,
 * the per-item isolation loop, and the response shape. Pro consumes this too.
 */
class BulkActionHelper
{
    /** Maximum number of items a single bulk request may process. */
    const MAX = 200;

    /**
     * Normalize a request id list to unique positive ints.
     *
     * @param mixed $ids
     * @return int[]
     */
    public static function sanitizeIds($ids)
    {
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($ids)) {
            return [];
        }

        $ids = array_filter(array_map([Sanitizer::class, 'sanitizeInt'], $ids), function ($id) {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    /**
     * Run a per-item bulk op. The post cache is primed once so per-item
     * type/status checks are cache hits; a falsy return or thrown error fails
     * that id without aborting the batch.
     *
     * @param int[]    $ids
     * @param callable $op fn(int $id): bool
     * @return array{affected_ids: int[], failed_ids: int[]}
     */
    public static function run(array $ids, callable $op)
    {
        if (!empty($ids)) {
            _prime_post_caches($ids, false, false);
        }

        $affected = [];
        $failed = [];

        foreach ($ids as $id) {
            $id = absint($id);
            if (!$id) {
                continue;
            }

            try {
                if ($op($id)) {
                    $affected[] = $id;
                } else {
                    $failed[] = $id;
                }
            } catch (\Exception $e) {
                $failed[] = $id;
            }
        }

        return [
            'affected_ids' => array_values(array_unique($affected)),
            'failed_ids'   => array_values(array_unique($failed)),
        ];
    }

    /**
     * Wrap a run() result in the standard response envelope.
     *
     * @param array{affected_ids: int[], failed_ids: int[]} $result
     * @param string $message Optional override; falls back to the count summary.
     * @return array{message: string, data: array}
     */
    public static function format(array $result, $message = '')
    {
        $affected = array_values(array_unique(array_map('intval', $result['affected_ids'] ?? [])));
        $failed   = array_values(array_unique(array_map('intval', $result['failed_ids'] ?? [])));

        return [
            'message' => $message !== '' ? $message : self::message(count($affected), count($failed)),
            'data'    => [
                'affected_ids'   => $affected,
                'affected_count' => count($affected),
                'failed_ids'     => $failed,
                'failed_count'   => count($failed),
            ],
        ];
    }

    /**
     * Count summary for a bulk result (all / partial / none).
     *
     * @return string
     */
    public static function message($affectedCount, $failedCount)
    {
        if ($affectedCount === 0) {
            return __('No items were updated', 'fluent-player');
        }

        if ($failedCount === 0) {
            // translators: %d is the number of items updated.
            return sprintf(_n('%d item updated', '%d items updated', $affectedCount, 'fluent-player'), $affectedCount);
        }

        // translators: 1: number updated, 2: total selected.
        return sprintf(__('%1$d of %2$d items updated', 'fluent-player'), $affectedCount, $affectedCount + $failedCount);
    }
}
