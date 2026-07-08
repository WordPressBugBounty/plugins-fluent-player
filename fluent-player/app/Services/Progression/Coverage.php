<?php

namespace FluentPlayer\App\Services\Progression;

if (!defined('ABSPATH')) exit;

/**
 * Pure coverage math — mirrors resources/js/progression/coverage.js.
 * Any change here must keep the shared conformance fixture green in both runtimes.
 */
class Coverage
{
    const EPSILON = 0.1;

    /**
     * @param array $segments list of ['start' => float, 'end' => float]
     * @return array merged, non-overlapping, ascending segments
     */
    public static function merge(array $segments, $epsilon = self::EPSILON)
    {
        $clean = [];
        foreach ($segments as $s) {
            if (!isset($s['start'], $s['end'])) {
                continue;
            }
            $start = (float) $s['start'];
            $end = (float) $s['end'];
            if ($end > $start) {
                $clean[] = ['start' => $start, 'end' => $end];
            }
        }

        if (empty($clean)) {
            return [];
        }

        usort($clean, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $merged = [array_shift($clean)];
        foreach ($clean as $current) {
            $lastIndex = count($merged) - 1;
            if ($current['start'] <= $merged[$lastIndex]['end'] + $epsilon) {
                $merged[$lastIndex]['end'] = max($merged[$lastIndex]['end'], $current['end']);
            } else {
                $merged[] = $current;
            }
        }

        return $merged;
    }

    public static function sumWatched(array $segments)
    {
        $total = 0.0;
        foreach (self::merge($segments) as $s) {
            $total += $s['end'] - $s['start'];
        }
        return $total;
    }

    /**
     * @return float ratio in 0..1
     */
    public static function ratio(array $segments, $duration)
    {
        $duration = (float) $duration;
        if ($duration <= 0) {
            return 0.0;
        }
        return min(self::sumWatched($segments) / $duration, 1.0);
    }
}
