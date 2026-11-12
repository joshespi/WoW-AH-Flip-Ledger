<?php

namespace App\Services;

use App\Models\User;
use App\Support\Bulk;
use App\Support\Game;
use App\Support\Utc;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The opt-in public scoreboard. Entries are computed from the same ledger replay the dashboard
 * shows (so the P/L is the user's own), but who is listed is decided when the board is read:
 * opting out or changing a nickname takes effect at once, whatever the last build held.
 */
class ScoreboardService
{
    /** period => window in days (null: all time). The first is the default. */
    public const PERIODS = ['7d' => 7, '30d' => 30, 'all' => null];

    /** metric => entries column. The first is the default. */
    public const METRICS = ['pnl' => 'pnl_copper', 'sales' => 'sales_copper', 'units' => 'units'];

    public function __construct(private PositionsService $positions) {}

    /** @return array<string, string> period => label */
    public static function periodLabels(): array
    {
        return ['7d' => __('7 days'), '30d' => __('30 days'), 'all' => __('All time')];
    }

    /** @return array<string, string> metric => label */
    public static function metricLabels(): array
    {
        return ['pnl' => __('Realized P/L'), 'sales' => __('Sales'), 'units' => __('Units')];
    }

    /**
     * Join (entries built at once unless there are some) or leave (entries deleted) the board.
     * The nickname is read with the board, so changing it needs no rebuild.
     */
    public function setMembership(User $user, bool $on, ?string $nickname): void
    {
        $user->forceFill(['scoreboard_opt_in' => $on, 'scoreboard_nickname' => $nickname])->save();

        if (! $on) {
            $this->forget($user);
        } elseif (! DB::table('scoreboard_entries')->ownedBy($user)->exists()) {
            $this->rebuildFor($user);
        }
    }

    /** Rebuilds every listed user; a failure skips that user only. Returns users rebuilt. */
    public function rebuild(?int $now = null): int
    {
        $now ??= time();
        $n = 0;

        // Anyone no longer listed leaves no rows behind.
        DB::table('scoreboard_entries')->whereNotIn('user_id', User::onScoreboard()->select('id'))->delete();

        foreach (User::onScoreboard()->lazyById() as $user) {
            try {
                $this->rebuildFor($user, $now);
                $n++;
            } catch (\Throwable $e) {
                Log::warning('scoreboard rebuild failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /** One user's entries for each game they play and each period. */
    public function rebuildFor(User $user, ?int $now = null): void
    {
        $now ??= time();
        $games = $user->enabledGames();
        $rows = [];

        $since = array_map(fn (?int $days) => $days === null ? null : Utc::daysAgo($days, $now), self::PERIODS);

        // Net sales (after the AH cut) and units per period from the sales log, in one pass per game.
        $sums = [];
        $bindings = [];

        foreach (array_values($since) as $i => $from) {
            $case = $from === null ? '' : 'case when sold_at >= ? then ';
            $end = $from === null ? '' : ' else 0 end';
            $sums[] = "coalesce(sum({$case}qty * unit_price_copper{$end}), 0) as copper{$i}, coalesce(sum({$case}qty{$end}), 0) as units{$i}";
            array_push($bindings, ...($from === null ? [] : [Utc::sql($from), Utc::sql($from)]));
        }

        foreach ($games as $game) {
            $events = $this->positions->realizedEvents($user, $game);
            $sales = DB::table('tsm_sales')->ownedBy($user)->inGame($game)
                ->where('sold_at', '<=', Utc::sql($now))
                ->selectRaw(implode(', ', $sums), $bindings)
                ->first();

            foreach (array_keys(self::PERIODS) as $i => $period) {
                $from = $since[$period];
                $in = array_filter($events, fn (array $e) => ($from === null || $e[0] >= $from) && $e[0] <= $now);

                $rows[] = [
                    'user_id' => $user->id,
                    'game' => $game,
                    'period' => $period,
                    'pnl_copper' => array_sum(array_column($in, 1)),
                    'sales_copper' => (int) $sales->{"copper{$i}"},
                    'units' => (int) $sales->{"units{$i}"},
                    'flips' => count($in),
                    'computed_at' => Utc::sql($now),
                ];
            }
        }

        DB::transaction(function () use ($user, $games, $rows) {
            DB::table('scoreboard_entries')->ownedBy($user)->whereNotIn('game', $games)->delete();
            Bulk::upsert('scoreboard_entries', $rows, ['user_id', 'game', 'period'], ['pnl_copper', 'sales_copper', 'units', 'flips', 'computed_at']);
        });
    }

    /** Removes a user from the board. */
    public function forget(User $user): void
    {
        DB::table('scoreboard_entries')->ownedBy($user)->delete();
    }

    /**
     * The ranked rows for one game and period, best first. Only the nickname is read from
     * users, never the name or email.
     *
     * @return list<array{rank: int, user_id: int, nickname: string, pnl: int, sales: int, units: int, flips: int, computed_at: string}>
     */
    public function board(string $game, string $period, string $metric, int $limit = 50): array
    {
        // The metric becomes a column name: known keys only.
        $column = self::METRICS[$metric] ?? throw new \InvalidArgumentException("Unknown metric {$metric}.");

        $rows = $this->listed()
            ->inGame($game, 'e.game')->where('e.period', $period)->where('e.units', '>', 0)
            ->orderByDesc('e.'.$column)->orderByDesc('e.units')->orderBy('e.user_id')
            ->limit($limit)
            ->select('e.user_id', 'u.scoreboard_nickname as nickname', 'e.pnl_copper', 'e.sales_copper', 'e.units', 'e.flips', 'e.computed_at')
            ->get();

        $out = [];

        foreach ($rows as $i => $r) {
            $out[] = [
                'rank' => $i + 1,
                'user_id' => (int) $r->user_id,
                'nickname' => (string) $r->nickname,
                'pnl' => (int) $r->pnl_copper,
                'sales' => (int) $r->sales_copper,
                'units' => (int) $r->units,
                'flips' => (int) $r->flips,
                'computed_at' => (string) $r->computed_at,
            ];
        }

        return $out;
    }

    /**
     * Games that have anyone listed, in display order; retail is always there.
     *
     * @return list<string>
     */
    public function games(): array
    {
        $have = $this->listed()->where('e.units', '>', 0)->distinct()->pluck('e.game')->all();

        return Game::normalize([Game::RETAIL, ...$have]);
    }

    /** Entries of users listed right now (User::onScoreboard), joined as `u` for the nickname. */
    private function listed(): Builder
    {
        return DB::table('scoreboard_entries as e')
            ->joinSub(User::onScoreboard()->select('id', 'scoreboard_nickname'), 'u', 'u.id', '=', 'e.user_id');
    }
}
