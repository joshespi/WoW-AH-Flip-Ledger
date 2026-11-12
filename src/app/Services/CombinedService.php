<?php

namespace App\Services;

use App\Models\User;
use App\Support\Game;
use App\Support\Utc;

/**
 * The Both screen: each game's portfolio side by side plus a total. Games are never merged row
 * by row (the same item id is a different market in each), only summed.
 */
class CombinedService
{
    /** Window for "flips per day". */
    private const FLIP_DAYS = 30;

    public function __construct(private PositionsService $positions, private RealizedRangesService $ranges) {}

    /**
     * @return array{
     *     portfolios: array<string, Portfolio>,
     *     summary: array<string, array<string, ?int>>,
     *     compare: array<string, array<string, int|float|null>>,
     *     ranges: array<string, array<string, mixed>>,
     *     rows: list<array{game: string, row: PositionRow}>
     * }
     */
    public function forUser(User $user, ?int $now = null): array
    {
        $now ??= time();
        $portfolios = [];

        foreach ($user->enabledGames() as $game) {
            $portfolios[$game] = $this->positions->forUser($user, $game);
        }

        $summary = array_map(fn (Portfolio $p) => $p->summary, $portfolios);

        // Null (no price feed) adds nothing: the market totals are the priced games' only.
        foreach (array_keys(reset($summary)) as $k) {
            $known = array_filter(array_column($summary, $k), fn (?int $v) => $v !== null);
            $summary['total'][$k] = $known === [] ? null : array_sum($known);
        }

        // Open positions of every game, largest cost first, each tagged with its game.
        $rows = [];

        foreach ($portfolios as $game => $p) {
            foreach ($p->open() as $row) {
                $rows[] = ['game' => $game, 'row' => $row];
            }
        }

        usort($rows, fn (array $a, array $b) => $b['row']->costBasis <=> $a['row']->costBasis);

        $events = array_map(fn (Portfolio $p) => $p->realizedEvents, $portfolios);

        return [
            'portfolios' => $portfolios,
            'summary' => $summary,
            'compare' => array_map(fn (Portfolio $p) => $this->compare($p, $now), $portfolios),
            'ranges' => $this->ranges->buildSplit($user, $events, $now),
            'rows' => $rows,
        ];
    }

    /** @return array<string, int|float|null> */
    private function compare(Portfolio $p, int $now): array
    {
        $open = $closed = $sold = $held = 0;

        foreach ($p->positions as $r) {
            $r->closed() ? $closed++ : $open++;
            $sold += $r->soldQty;
            $held += $r->held;
        }

        $since = Utc::daysAgo(self::FLIP_DAYS, $now);
        $flips = count(array_filter($p->realizedEvents, fn (array $e) => $e[0] >= $since && $e[0] <= $now));

        return [
            'open' => $open,
            'closed' => $closed,
            'sold' => $sold,
            // Units sold out of everything bought and still tracked: sold / (sold + held).
            'sell_through' => $sold + $held > 0 ? $sold / ($sold + $held) : null,
            'flips_per_day' => $flips / self::FLIP_DAYS,
        ];
    }
}
