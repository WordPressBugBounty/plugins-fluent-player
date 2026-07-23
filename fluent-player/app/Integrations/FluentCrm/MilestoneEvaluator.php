<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\Progression\Coverage;
use FluentPlayer\App\Services\Progression\ProgressionService;
use FluentPlayer\App\Services\Behavior\Milestones;
use FluentPlayer\Framework\Support\Arr;

/**
 * Server-authoritative milestone evaluator.
 *
 * The client's report is only a request-to-check: this service re-derives distinct
 * coverage from clamped segments against the server-owned duration, bounds how fast
 * that coverage may grow against real wall-clock time (temporal-plausibility gate),
 * then diffs against the already-fired set. Coverage milestones are computed ONLY when
 * the duration is server-owned; without one, quartiles and threshold completions are
 * withheld and only started / ended-completed remain. A tampered client cannot inflate
 * coverage past the real media length, grow it faster than real time, or re-fire.
 *
 * evaluate() is pure (no WordPress) so the trust logic is unit-testable without a
 * bootstrap; the stateful load/store/emit lives in the handler that calls it.
 */
class MilestoneEvaluator
{
    const MAX_RATE = 2.0;          // fastest plausible watch speed (2× real time)
    const GROWTH_GRACE = 2.0;      // seconds of slack for buffering / clock skew
    const STARTED_MIN_SECONDS = 1.0;
    const MAX_UNION_SEGMENTS = 1000;

    /**
     * Bound the CRM behavior-state union row by closing the smallest gaps (never by
     * dropping intervals), so a fragmentation flood can't bloat the stored meta while
     * sumWatched stays monotonic. Scoped to this consumer — the shared progression
     * service is left untouched. Input must be Coverage::merge() output.
     */
    public static function boundUnion(array $intervals, $max = self::MAX_UNION_SEGMENTS)
    {
        $n = count($intervals);
        if ($max < 1 || $n <= $max) {
            return $intervals;
        }

        // close exactly the (n - max) smallest gaps, ranked by size then position, so
        // a uniform-gap union collapses by the minimum needed rather than all at once
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $gaps[] = ['gap' => $intervals[$i]['start'] - $intervals[$i - 1]['end'], 'at' => $i];
        }
        usort($gaps, function ($a, $b) {
            return $a['gap'] <=> $b['gap'] ?: $a['at'] <=> $b['at'];
        });
        $close = [];
        foreach (array_slice($gaps, 0, $n - $max) as $g) {
            $close[$g['at']] = true;
        }

        $result = [$intervals[0]];
        for ($i = 1; $i < $n; $i++) {
            if (isset($close[$i])) {
                $last = count($result) - 1;
                $result[$last]['end'] = max($result[$last]['end'], $intervals[$i]['end']);
            } else {
                $result[] = $intervals[$i];
            }
        }
        return $result;
    }

    /**
     * Distinct watched seconds cannot grow faster than real elapsed time allows.
     * Returns the accepted cumulative watched seconds (never above the incoming value,
     * never growing past prev + elapsed*maxRate + grace). Defeats an instant
     * full-coverage POST: with elapsed≈0 the growth allowance is ~grace only.
     */
    public static function cappedWatched($prevWatched, $incomingWatched, $elapsedSinceLast, $maxRate = self::MAX_RATE, $grace = self::GROWTH_GRACE)
    {
        $prev = max(0.0, (float) $prevWatched);
        $incoming = max(0.0, (float) $incomingWatched);
        if ($incoming <= $prev) {
            return $incoming;
        }
        $maxGrowth = (max(0.0, (float) $elapsedSinceLast) * (float) $maxRate) + (float) $grace;
        return min($incoming, $prev + $maxGrowth);
    }

    /**
     * Reached milestones minus the already-fired set, order preserved.
     *
     * @param string[] $firedIdentities
     * @param array $reached list of ['key','boundary'] from Milestones::reached()
     * @return array list of ['key','boundary','id']
     */
    public static function freshMilestones(array $firedIdentities, array $reached)
    {
        $firedSet = array_fill_keys($firedIdentities, true);
        $fresh = [];
        foreach ($reached as $m) {
            $id = Milestones::key($m);
            // Arr::exists, not Arr::has — milestone ids contain dots ("q50:0.5000")
            if (!Arr::exists($firedSet, $id)) {
                $fresh[] = ['key' => $m['key'], 'boundary' => $m['boundary'], 'id' => $id];
            }
        }
        return $fresh;
    }

    /**
     * Pure evaluation. Given client evidence, the server-owned duration, the armed
     * milestone config, the already-fired identities, and this identity's prior watched
     * seconds + seconds since its last ping, decide which milestones fire now.
     *
     * @param array $args {
     *   segments:        array  raw client segments
     *   serverDuration:  float  server-owned duration (0 if unknown)
     *   clientDuration:  float  client-reported duration (fallback only)
     *   clientStarted:   bool   client says playback started (used only when duration unknown)
     *   ended:           bool
     *   armed:           array  ['started'=>bool,'quartiles'=>string[],'completions'=>float[]]
     *   firedIdentities: string[]
     *   prevWatched:     float  previously-accepted cumulative watched seconds
     *   elapsedSinceLast:float  wall-clock seconds since this identity's last ping
     * }
     * @return array {
     *   coverage:       float 0..1
     *   durationSource: 'server'|'client'|'none'
     *   watched:        float accepted cumulative watched seconds
     *   fired:          array list of ['key','boundary','id']
     * }
     */
    public static function evaluate(array $args)
    {
        $segments = (array) Arr::get($args, 'segments', []);
        $resolved = ProgressionService::pickDuration(
            Arr::get($args, 'serverDuration', 0),
            Arr::get($args, 'clientDuration', 0)
        );
        $duration = $resolved['duration'];
        // client duration is untrusted — coverage milestones need a server denominator
        $durationKnown = $duration > 0 && $resolved['source'] === 'server';

        $armed = self::normalizeArmed(Arr::get($args, 'armed', []));
        $firedIdentities = (array) Arr::get($args, 'firedIdentities', []);

        $ended = Arr::isTrue($args, 'ended');
        $stored = (array) Arr::get($args, 'storedSegments', []);

        if ($durationKnown) {
            // Cumulative distinct coverage across sittings: merge the stored union with
            // this ping's segments (both clamped to the server duration) so a returning
            // viewer's later milestones fire, then bound how fast that total may grow.
            $union = self::boundUnion(ProgressionService::accumulateSegments($stored, $segments, $duration));
            $cumulativeWatched = Coverage::sumWatched($union);
            $watched = self::cappedWatched(
                Arr::get($args, 'prevWatched', 0),
                $cumulativeWatched,
                Arr::get($args, 'elapsedSinceLast', 0)
            );
            $coverage = min($watched / $duration, 1.0);
            $started = $watched >= self::STARTED_MIN_SECONDS;
        } else {
            $union = $stored;
            $watched = 0.0;
            $coverage = 0.0;
            $started = Arr::isTrue($args, 'clientStarted');
        }

        $evidence = [
            'coverage'      => $coverage,
            'durationKnown' => $durationKnown,
            'started'       => $started,
            'ended'         => $ended,
            'maxCoverage'   => $durationKnown && $duration > 1 ? ($duration - 1) / $duration : 1.0,
        ];

        $reached = Milestones::reached($evidence, $armed);
        $fresh = self::freshMilestones($firedIdentities, $reached);

        return [
            'coverage'       => $coverage,
            'durationSource' => $resolved['source'],
            'watched'        => $watched,
            'union'          => $union,
            'fired'          => $fresh,
        ];
    }

    public static function normalizeArmed($armed)
    {
        $armed = is_array($armed) ? $armed : [];

        $quartiles = [];
        foreach ((array) Arr::get($armed, 'quartiles', []) as $q) {
            if (in_array($q, ['q25', 'q50', 'q75'], true)) {
                $quartiles[] = $q;
            }
        }

        $completions = [];
        foreach ((array) Arr::get($armed, 'completions', []) as $c) {
            $c = (float) $c;
            if ($c > 0 && $c <= 1) {
                $completions[] = $c;
            }
        }

        return [
            'started'     => Arr::isTrue($armed, 'started'),
            'quartiles'   => $quartiles,
            'completions' => $completions,
        ];
    }
}
