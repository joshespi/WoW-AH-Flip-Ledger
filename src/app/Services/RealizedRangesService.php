<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Realized P/L over calendar periods in the user's timezone (today, this week from Monday, this
 * month, this quarter, all time), each with the previous period to compare and a bucketed series
 * for the dashboard chart. Input is Portfolio::$realizedEvents, so the all-time total is
 * the portfolio's realized figure.
 */
class RealizedRangesService
{
    /** Range key => [period unit, chart bucket unit, bucket label format]. */
    private const RANGES = [
        'day' => ['day', 'hour', 'ga'],
        'week' => ['week', 'day', 'D j'],
        'month' => ['month', 'day', 'M j'],
        'quarter' => ['quarter', 'week', 'M j'],
        'all' => [null, 'month', 'M Y'],
    ];

    /**
     * Range key => [this period's label, the previous period's label].
     *
     * @return array<string, array{string, string}>
     */
    public static function labels(): array
    {
        return [
            'day' => [__('Today'), __('Yesterday')],
            'week' => [__('This week'), __('Last week')],
            'month' => [__('This month'), __('Last month')],
            'quarter' => [__('This quarter'), __('Last quarter')],
            'all' => [__('All time'), ''],
        ];
    }

    /**
     * Keyed by range; `prev` is null for all time.
     *
     * @param  list<array{int, int}>  $events  [epoch ts, copper]
     * @param  list<array{int, int}>|null  $axisEvents  events that decide where all time starts (default: $events), so several series share one axis
     * @return array<string, array{pnl: int, prev: ?int, labels: list<string>, pnls: list<int>, cum: list<int>}>
     */
    public function build(User $user, array $events, ?int $now = null, ?array $axisEvents = null): array
    {
        $now = CarbonImmutable::createFromTimestamp($now ?? time(), $user->timezone);
        $out = [];

        foreach (self::RANGES as $key => [$period, $step, $format]) {
            // All time has no previous period and also counts anything dated after now.
            $start = $period !== null ? $now->startOf($period) : $this->firstMonth($user, $axisEvents ?? $events, $now);
            $from = $start->getTimestamp();
            $to = $period !== null ? $now->getTimestamp() : PHP_INT_MAX;
            $prevFrom = $period !== null ? $start->sub($period, 1)->getTimestamp() : null;

            // Buckets begin at the step boundary (a quarter starts mid-week) so the first one is whole.
            $starts = [];
            $labels = [];

            for ($c = $start->startOf($step); $c <= $now; $c = $c->add($step, 1)) {
                $starts[] = $c->getTimestamp();
                $labels[] = $c->format($format);
            }

            $buckets = array_fill(0, count($starts), 0);
            $last = count($starts) - 1;
            $pnl = 0;
            $prev = $prevFrom === null ? null : 0;

            foreach ($events as [$ts, $copper]) {
                if ($ts >= $from && $ts <= $to) {
                    $pnl += $copper;

                    // Last bucket starting at or before $ts; later (future) events land in the last one.
                    $lo = 0;
                    $hi = $last;

                    while ($lo < $hi) {
                        $mid = intdiv($lo + $hi + 1, 2);
                        $starts[$mid] <= $ts ? $lo = $mid : $hi = $mid - 1;
                    }

                    $buckets[$lo] += $copper;
                } elseif ($prevFrom !== null && $ts >= $prevFrom && $ts < $from) {
                    $prev += $copper;
                }
            }

            $cum = [];
            $run = 0;

            foreach ($buckets as $v) {
                $cum[] = $run += $v;
            }

            $out[$key] = ['pnl' => $pnl, 'prev' => $prev, 'labels' => $labels, 'pnls' => array_values($buckets), 'cum' => $cum];
        }

        return $out;
    }

    /**
     * One chart axis, a series per game plus the total. Every game's buckets line up because the
     * total's events decide the axis.
     *
     * @param  array<string, list<array{int, int}>>  $byGame  game => [epoch ts, copper]
     * @return array<string, array{labels: list<string>, pnl: array<string, int>, cum: array<string, list<int>>}>
     */
    public function buildSplit(User $user, array $byGame, ?int $now = null): array
    {
        $all = array_merge(...array_values($byGame) ?: [[]]);
        $series = ['total' => $this->build($user, $all, $now)];

        foreach ($byGame as $game => $events) {
            $series[$game] = $this->build($user, $events, $now, $all);
        }

        $out = [];

        foreach ($series['total'] as $range => $t) {
            $out[$range] = ['labels' => $t['labels'], 'pnl' => [], 'cum' => []];

            foreach ($series as $key => $s) {
                $out[$range]['pnl'][$key] = $s[$range]['pnl'];
                $out[$range]['cum'][$key] = $s[$range]['cum'];
            }
        }

        return $out;
    }

    /** First month with any event, so the all-time chart starts where the history does. */
    private function firstMonth(User $user, array $events, CarbonImmutable $now): CarbonImmutable
    {
        if ($events === []) {
            return $now->startOf('month');
        }

        $first = min(array_column($events, 0));

        return CarbonImmutable::createFromTimestamp(min($first, $now->getTimestamp()), $user->timezone)->startOf('month');
    }
}
