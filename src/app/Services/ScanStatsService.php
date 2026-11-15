<?php

namespace App\Services;

use App\Models\User;
use App\Support\Bounds;
use App\Support\Bulk;
use App\Support\Rows;
use App\Support\Stats;
use App\Support\Utc;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Prices for games with no Blizzard feed (Forever, Classic): the user's own addon scans, stored
 * hourly in scan_prices, and the item_stats-shaped numbers computed from them in scan_stats. Per
 * user, so nobody else's upload can move them. Same rules as PriceStatsService (windows anchored
 * at the newest scan, `days_of_data` for the min_days gate), except there is no sold-per-day
 * estimate: scans are too sparse to tell sales from cancels.
 */
class ScanStatsService
{
    /** A scan's clock is the player's PC: allow a little skew, never a future anchor. */
    private const FUTURE_SLACK = Utc::HOUR;

    /**
     * Stores the valid rows of an upload's price scans, one per item per hour. The addon keeps the
     * newest scan of an hour, so a later upload replaces what an earlier one stored for that hour.
     *
     * @param  list<array<string, string>>  $rows  the parser's rows: time, itemString, floor, quantity, auctions
     * @return array{seen: int}
     */
    public function store(User $user, string $game, array $rows, ?int $now = null): array
    {
        $out = [];
        $newest = [];

        foreach ($rows as $r) {
            $time = Bounds::count($r['time'] ?? null);
            $item = Bounds::count(substr($r['itemString'] ?? '', 2));
            $floor = Bounds::count($r['floor'] ?? null);
            $qty = Bounds::count($r['quantity'] ?? null, Bounds::MAX_INVENTORY);
            $auctions = Bounds::count($r['auctions'] ?? null, Bounds::MAX_QTY);

            if ($time === null || $item === null || $floor === null || $qty === null || $auctions === null
                || ! Bounds::validItemId($item) || ! Bounds::validPrice($floor, 1) || $qty < 1
                || ! Bounds::plausibleTime($time, self::FUTURE_SLACK, $now)) {
                continue;
            }

            $hour = intdiv($time, Utc::HOUR) * Utc::HOUR;
            $key = $hour.':'.$item;

            // The newest scan of the hour in this file wins.
            if (isset($newest[$key]) && $newest[$key] > $time) {
                continue;
            }

            $newest[$key] = $time;
            $out[$key] = ['user_id' => $user->id, 'region' => $user->region, 'game' => $game, 'taken_at' => Utc::sql($hour),
                'item_id' => $item, 'floor' => $floor, 'quantity' => $qty, 'num_auctions' => $auctions];
        }

        $rows = array_values($out);
        Bulk::upsert('scan_prices', $rows, ['user_id', 'region', 'game', 'taken_at', 'item_id'], ['floor', 'quantity', 'num_auctions']);

        return ['seen' => count($rows)];
    }

    /**
     * Rebuilds the user's scan_stats for a game. Runs under the ingest lock (one writer per user).
     *
     * @return int items written
     */
    public function recompute(User $user, string $game): int
    {
        $scans = fn (): Builder => DB::table('scan_prices')->ownedBy($user)->where('region', $user->region)->where('game', $game);
        $stats = fn (): Builder => DB::table('scan_stats')->ownedBy($user)->where('region', $user->region)->where('game', $game);

        $latest = $scans()->max('taken_at');

        if ($latest === null) {
            $stats()->delete();

            return 0;
        }

        $anchor = Utc::ts($latest);
        $from60 = Utc::day(Utc::daysAgo(59, $anchor));
        $from30 = Utc::day(Utc::daysAgo(29, $anchor));
        $dayAgo = Utc::sql(Utc::daysAgo(1, $anchor));

        $rows = $scans()->where('taken_at', '>=', $from60.' 00:00:00')->orderBy('item_id')->orderBy('taken_at')
            ->select('item_id', 'taken_at', 'floor', 'quantity', 'num_auctions')->cursor();

        $now = Utc::sql();
        $out = [];

        foreach (Rows::byItem($rows) as $id => $group) {
            $newest = end($group);

            $byDay = [];
            $recent = [];

            foreach ($group as $r) {
                $byDay[substr((string) $r->taken_at, 0, 10)][] = (int) $r->floor;

                if ($r->taken_at > $dayAgo) {
                    $recent[] = (int) $r->floor;
                }
            }

            // A daily median per day, like price_daily's, then the same 30 d and 60 d summaries.
            $medians = array_map(Stats::median(...), $byDay);
            $thirty = array_filter($medians, static fn (int $m, string $day): bool => $day >= $from30, ARRAY_FILTER_USE_BOTH);

            $out[] = [
                'user_id' => $user->id,
                'region' => $user->region,
                'game' => $game,
                'item_id' => $id,
                'taken_at' => $newest->taken_at,
                'floor' => $newest->floor,
                'quantity' => $newest->quantity,
                'num_auctions' => $newest->num_auctions,
                'recent' => $recent === [] ? null : Stats::median($recent),
                'typical' => Stats::median(array_values($thirty ?: $medians)),
                'hist60' => Stats::mean(array_values($medians)),
                'sold_per_day_est' => null,
                'days_of_data' => count($medians),
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($out, $stats) {
            // Replaced whole: items with no scan left in the window have nothing to show.
            $stats()->delete();
            Bulk::insertOrIgnore('scan_stats', $out);
        });

        return count($out);
    }

    /** Deletes scans older than $days (stats were computed from at most 60 d anyway). */
    public function prune(int $days): int
    {
        return DB::table('scan_prices')->where('taken_at', '<', Utc::sql(Utc::daysAgo($days)))->delete();
    }
}
