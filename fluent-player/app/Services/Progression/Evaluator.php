<?php

namespace FluentPlayer\App\Services\Progression;

if (!defined('ABSPATH')) exit;

/**
 * Pure completion decision — mirrors resources/js/progression/evaluate.js.
 * The server is the source of truth: it recomputes coverage from raw segments
 * (never a client-supplied ratio) before asking evaluate().
 */
class Evaluator
{
    private static function clamp01($value)
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        return max(0.0, min((float) $value, 1.0));
    }

    /**
     * @param array $evidence ['ratio' => 0..1, 'ended' => bool, 'mutedAutoplayActive' => bool]
     * @param array $policy   ['threshold' => 0..1, 'countMutedAutoplay' => bool]
     * @return array ['complete' => bool, 'reason' => 'ended'|'threshold'|null]
     */
    public static function evaluate(array $evidence, array $policy)
    {
        $ratio = self::clamp01(isset($evidence['ratio']) ? $evidence['ratio'] : 0);
        $ended = !empty($evidence['ended']);
        $mutedAutoplayActive = !empty($evidence['mutedAutoplayActive']);

        $threshold = self::clamp01(isset($policy['threshold']) ? $policy['threshold'] : 0);
        $countMutedAutoplay = !empty($policy['countMutedAutoplay']);

        if ($mutedAutoplayActive && !$countMutedAutoplay) {
            return ['complete' => false, 'reason' => null];
        }

        if ($ratio >= $threshold) {
            return ['complete' => true, 'reason' => $ended ? 'ended' : 'threshold'];
        }

        return ['complete' => false, 'reason' => null];
    }

    /**
     * @param array $flags  ['ended' => bool, 'mutedAutoplayActive' => bool]
     */
    public static function evaluateFromSegments(array $segments, $duration, array $flags, array $policy)
    {
        $ratio = Coverage::ratio($segments, $duration);
        return self::evaluate(
            [
                'ratio' => $ratio,
                'ended' => !empty($flags['ended']),
                'mutedAutoplayActive' => !empty($flags['mutedAutoplayActive']),
            ],
            $policy
        );
    }
}
