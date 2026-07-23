<?php

namespace FluentPlayer\App\Services\Behavior;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

/**
 * Pure milestone-bucket logic — mirrors resources/js/behavior/milestones.js.
 * Any change here must keep resources/milestones/conformance.json green in both runtimes.
 */
class Milestones
{
    const QUARTILE_BOUNDARIES = ['q25' => 0.25, 'q50' => 0.5, 'q75' => 0.75];

    public static function reached(array $evidence, array $armed)
    {
        $coverage = self::clamp01(Arr::get($evidence, 'coverage', 0));
        $durationKnown = Arr::isTrue($evidence, 'durationKnown');
        $started = Arr::isTrue($evidence, 'started');
        $ended = Arr::isTrue($evidence, 'ended');
        // highest coverage the duration math can ever measure (rounding and container
        // padding eat the final second); thresholds above it are capped so a 100%
        // completion stays reachable
        $maxCoverage = self::clamp01(Arr::get($evidence, 'maxCoverage', 1));
        if ($maxCoverage <= 0) {
            $maxCoverage = 1.0;
        }

        $quartiles = (array) Arr::get($armed, 'quartiles', []);
        $completions = (array) Arr::get($armed, 'completions', []);

        $reached = [];

        if (Arr::isTrue($armed, 'started') && $started) {
            $reached[] = ['key' => 'started', 'boundary' => null];
        }

        if ($durationKnown) {
            foreach (self::QUARTILE_BOUNDARIES as $key => $boundary) {
                if (in_array($key, $quartiles, true) && $coverage >= $boundary) {
                    $reached[] = ['key' => $key, 'boundary' => $boundary];
                }
            }
            $sorted = $completions;
            sort($sorted, SORT_NUMERIC);
            foreach ($sorted as $threshold) {
                if (self::isFraction($threshold) && $coverage >= min((float) $threshold, $maxCoverage)) {
                    $reached[] = ['key' => 'completed', 'boundary' => (float) $threshold];
                }
            }
        } elseif ($ended && count($completions) > 0) {
            $reached[] = ['key' => 'completed', 'boundary' => null];
        }

        return $reached;
    }

    public static function key(array $milestone)
    {
        $boundary = Arr::get($milestone, 'boundary');
        $boundary = $boundary === null ? 'x' : number_format((float) $boundary, 4, '.', '');
        return Arr::get($milestone, 'key', '') . ':' . $boundary;
    }

    private static function clamp01($value)
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        return max(0.0, min((float) $value, 1.0));
    }

    private static function isFraction($value)
    {
        return is_numeric($value) && (float) $value > 0 && (float) $value <= 1;
    }
}
