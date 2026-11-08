<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\User;
use App\Support\Utc;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The public /stats numbers. Anonymous by construction: counted accounts only (User::counted),
 * and the output is whole counts and dates (no money, ids, names, items or realms, nothing per user).
 */
class SiteStatsService
{
    public const DAYS = 30;

    private const CACHE_KEY = 'site-stats';

    /**
     * Fresh for 10 minutes, then served stale while one request rebuilds it.
     *
     * @return array{
     *     accounts: int, active_today: int, active_7d: int, active_30d: int, tracked_since: ?string,
     *     days: list<array{day: string, active: int, sales: int, buys: int, uploads: int}>
     * }
     */
    public function get(): array
    {
        return Cache::flexible(self::CACHE_KEY, [600, 1200], fn () => $this->build());
    }

    /** @return array<string, mixed> */
    public function build(?int $now = null): array
    {
        $now ??= time();
        $from = Utc::day($now - (self::DAYS - 1) * 86400);

        // Per UTC day. date() is the same call on MariaDB and SQLite, and a `Y-m-d` lower bound
        // compares the same against date and datetime columns on both.
        $series = fn (string $table, string $col, ?callable $where = null) => $this->counted($table)
            ->where("{$table}.{$col}", '>=', $from)
            ->when($where, $where)
            ->groupByRaw("date({$table}.{$col})")
            ->selectRaw("date({$table}.{$col}) as d, count(*) as n")
            ->pluck('n', 'd');

        // One row per user and day (the primary key), so a day's count is its active users.
        $activeByDay = $series('user_activity_days', 'day');
        $sales = $series('tsm_sales', 'sold_at');
        $buys = $series('trades', 'traded_at', fn ($q) => $q->where('trades.side', 'buy'));
        $uploads = $series('uploads', 'received_at', fn ($q) => $q->where('uploads.status', Upload::STATUS_OK));

        $active = $this->counted('user_activity_days')->where('user_activity_days.day', '>=', $from)
            ->selectRaw('count(distinct case when user_activity_days.day >= ? then user_activity_days.user_id end) as week, count(distinct user_activity_days.user_id) as month', [Utc::day($now - 6 * 86400)])
            ->first();

        $days = [];

        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $d = Utc::day($now - $i * 86400);
            $days[] = [
                'day' => $d,
                'active' => (int) ($activeByDay[$d] ?? 0),
                'sales' => (int) ($sales[$d] ?? 0),
                'buys' => (int) ($buys[$d] ?? 0),
                'uploads' => (int) ($uploads[$d] ?? 0),
            ];
        }

        // Activity is pruned after ledger.stats.activity_days, so the oldest day only means "tracking
        // started" while it is inside the window; after that the window is full and it is null.
        $first = $this->counted('user_activity_days')->min('user_activity_days.day');
        $first = $first === null ? null : substr((string) $first, 0, 10);

        return [
            'accounts' => User::counted()->count(),
            'active_today' => end($days)['active'],
            'active_7d' => (int) $active->week,
            'active_30d' => (int) $active->month,
            'tracked_since' => $first !== null && $first > $from ? $first : null,
            'days' => $days,
        ];
    }

    /** A table's rows that belong to counted accounts. */
    private function counted(string $table): Builder
    {
        return DB::table($table)->whereIn("{$table}.user_id", User::counted()->select('id'));
    }
}
