<?php

namespace FluentPlayer\App\Services\Progression;

if (!defined('ABSPATH')) exit;

/**
 * Server-authoritative trust boundary for video-progression completion.
 *
 * The client is never trusted: it sends raw watched segments, but the server
 * owns the duration and sanitizes segments against it before recomputing
 * coverage. A client cannot complete a step by posting ratio=1 — coverage is
 * always re-derived here from clamped intervals.
 *
 * Segment sanitization is intentionally server-only (not mirrored in JS): the
 * JS side computes coverage from its own clean tracker segments for the
 * optimistic unlock, whereas this boundary defends against tampered input.
 */
class ProgressionService
{
    // User-meta key (suffixed with the media id) holding the merged union of a
    // learner's watched segments for one media, so coverage survives reloads.
    const SEGMENTS_META_PREFIX = '_flp_watch_segments_';

    public static function defaultPolicy()
    {
        return [
            'threshold'          => 0.9,
            'countMutedAutoplay' => false,
            'autoComplete'       => false,
            // When true, distinct coverage is accumulated server-side across
            // sessions (opt-in per LMS). Default off keeps record() stateless.
            'accumulate'         => false,
        ];
    }

    /**
     * Pure union of stored + freshly-watched segments, each clamped to the
     * server duration and merged into non-overlapping intervals. This union is
     * what completion is judged on; unit-testable without WordPress.
     *
     * @return array list of ['start' => float, 'end' => float]
     */
    public static function accumulateSegments(array $stored, array $incoming, $duration)
    {
        return Coverage::merge(array_merge(
            self::sanitizeSegments($stored, $duration),
            self::sanitizeSegments($incoming, $duration)
        ));
    }

    /**
     * Load the learner's stored watched-segment union for a media, merge the
     * freshly-watched segments in, persist it, and return the union. Keyed by
     * media (not step): watching the same video anywhere credits it once.
     * Both sides are clamped to the server duration, so a tampered meta row
     * cannot inflate coverage past the real video length.
     */
    protected static function mergeAndStoreSegments($userId, $mediaId, $incoming, $duration)
    {
        $key = self::SEGMENTS_META_PREFIX . (int) $mediaId;
        $stored = get_user_meta((int) $userId, $key, true);
        $stored = is_array($stored) ? $stored : [];

        // Without a usable duration sanitizeSegments() returns [], so persisting
        // here would wipe the learner's accumulated progress (e.g. an early
        // pagehide flush before metadata loads). Leave the store untouched.
        if ((float) $duration <= 0) {
            return $stored;
        }

        $merged = self::accumulateSegments($stored, is_array($incoming) ? $incoming : [], $duration);
        update_user_meta((int) $userId, $key, $merged);
        return $merged;
    }

    /**
     * Resolve which duration the server should trust. Server-owned duration
     * wins (tamper-proof); the client's reported duration is a flagged
     * fallback for media the server has no stored duration for.
     *
     * @return array ['duration' => float, 'source' => 'server'|'client'|'none']
     */
    public static function pickDuration($serverDuration, $clientDuration)
    {
        $server = (float) $serverDuration;
        if ($server > 0) {
            return ['duration' => $server, 'source' => 'server'];
        }
        $client = (float) $clientDuration;
        if ($client > 0) {
            return ['duration' => $client, 'source' => 'client'];
        }
        return ['duration' => 0.0, 'source' => 'none'];
    }

    /**
     * Clamp untrusted segments into [0, duration] and drop anything invalid.
     *
     * @param array $raw      list of ['start' => mixed, 'end' => mixed]
     * @param float $duration server-owned media duration
     * @return array clean list of ['start' => float, 'end' => float]
     */
    public static function sanitizeSegments($raw, $duration)
    {
        $duration = (float) $duration;
        if ($duration <= 0 || !is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $segment) {
            if (!is_array($segment) || !isset($segment['start'], $segment['end'])) {
                continue;
            }
            if (!is_numeric($segment['start']) || !is_numeric($segment['end'])) {
                continue;
            }
            $start = max(0.0, min((float) $segment['start'], $duration));
            $end = max(0.0, min((float) $segment['end'], $duration));
            if ($end > $start) {
                $clean[] = ['start' => $start, 'end' => $end];
            }
        }
        return $clean;
    }

    /**
     * Recompute coverage from sanitized segments and decide completion.
     * Pure (no WordPress) so it is unit-testable without a bootstrap.
     *
     * @param array $flags ['ended' => bool, 'mutedAutoplayActive' => bool]
     * @return array ['complete' => bool, 'reason' => 'ended'|'threshold'|null]
     */
    public static function verdict($rawSegments, $duration, array $flags, array $policy)
    {
        $policy = array_merge(self::defaultPolicy(), $policy);
        $segments = self::sanitizeSegments($rawSegments, $duration);
        return Evaluator::evaluateFromSegments($segments, $duration, $flags, $policy);
    }

    /**
     * Server-authoritative distinct-coverage ratio (0..1) from raw segments —
     * the same anti-scrub value the verdict is derived from, exposed so watch
     * consumers (e.g. analytics) can persist real progress, not a client number.
     */
    public static function coverage($rawSegments, $duration)
    {
        return Coverage::ratio(self::sanitizeSegments($rawSegments, $duration), (float) $duration);
    }

    /**
     * Boundary used by watch-flush consumers: derive the verdict and announce
     * it so any LMS adapter (LearnDash first) can act on it.
     *
     * @param int   $mediaId
     * @param int   $userId
     * @param array $evidence ['segments' => array, 'duration' => float, 'durationSource' => string, 'ended' => bool, 'mutedAutoplayActive' => bool]
     * @param array $context  LMS context (e.g. ['course_id' => int, 'step_id' => int]) passed to the policy filter and the adapter
     * @param array $policy   overrides merged over defaultPolicy()
     * @return array verdict
     */
    public static function record($mediaId, $userId, array $evidence, array $context = [], array $policy = [])
    {
        $policy = apply_filters(
            'fluent_player/progression/policy',
            array_merge(self::defaultPolicy(), $policy),
            $mediaId,
            $userId,
            $context
        );

        $duration = isset($evidence['duration']) ? $evidence['duration'] : 0;
        $segments = isset($evidence['segments']) ? $evidence['segments'] : [];

        // Accumulate distinct coverage across sessions so completion survives a
        // reload or multi-sitting viewing. Opt-in per policy and login-only; the
        // union lives on the server, so it can't be forged from the client.
        if ($userId && !empty($policy['accumulate'])) {
            $segments = self::mergeAndStoreSegments($userId, $mediaId, $segments, $duration);
        }

        $verdict = self::verdict($segments, $duration, $evidence, $policy);

        $verdict = apply_filters('fluent_player/progression/verdict', $verdict, $mediaId, $userId, $context);

        do_action('fluent_player/watch_recorded', $mediaId, $userId, [
            'duration'       => (float) $duration,
            'durationSource' => isset($evidence['durationSource']) ? $evidence['durationSource'] : 'unknown',
            'coverage'       => self::coverage($segments, $duration),
            'verdict'        => $verdict,
            'policy'         => $policy,
            'context'        => $context,
        ]);

        return $verdict;
    }
}
