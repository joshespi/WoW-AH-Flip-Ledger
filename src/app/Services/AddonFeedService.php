<?php

namespace App\Services;

use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Arr;

/**
 * What the in-game addon shows, for one user: open positions, watchlist, the alerts that are on
 * right now, and how fresh the prices are. Read-only and built from the same services as the
 * screens, so the numbers match; derived fields stay null until an item has enough history.
 *
 * The uploader turns this into the addon's Data.lua. Money is copper (per unit for prices).
 * Free text (watchlist notes) is left out on purpose.
 */
class AddonFeedService
{
    public const SCHEMA = 1;

    public function __construct(
        private PositionsService $positions,
        private WatchlistService $watchlist,
        private AlertService $alerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $portfolio = $this->positions->forUser($user);
        $open = $portfolio->open();
        $cap = (int) config('ledger.addon.max_positions');
        $fresh = Freshness::for($user);
        $watch = $this->watchlist->rows($user);

        return [
            'schema' => self::SCHEMA,
            'generated_at' => $fresh->now,
            'region' => $user->region,
            'prices_at' => $fresh->pricesAt,
            'prices_stale' => $fresh->pricesStale(),
            'summary' => [
                'realized' => $portfolio->summary['realized'],
                'unrealized' => $portfolio->summary['unrealized'],
                'stock_at_floor' => $portfolio->summary['stock_at_floor'],
            ],
            'truncated' => count($open) > $cap,
            'positions' => array_map($this->position(...), array_slice($open, 0, $cap)),
            'watchlist' => array_map($this->watched(...), $watch),
            'alerts' => array_values(array_map($this->alert(...), array_filter($this->alerts->current($user, $watch, $portfolio), fn (array $a) => $a['on']))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function position(PositionRow $p): array
    {
        return [
            'item_id' => $p->itemId,
            'name' => $p->name,
            'held' => $p->held,
            'avg_cost' => $p->avgCost === null ? null : Money::round($p->avgCost),
            'break_even' => $p->breakEven === null ? null : (int) ceil($p->breakEven),
            'target_sell' => $p->targetSell === null ? null : (int) ceil($p->targetSell),
            'floor' => $p->floor,
            'recent' => $p->recent,
            'typical' => $p->typical,
            'unrealized' => $p->unrealized,
            'status' => $p->status,
            'market' => $p->market,
            'stats_ready' => $p->statsReady,
        ];
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function watched(array $w): array
    {
        return Arr::only($w, ['item_id', 'name', 'buy', 'sell', 'floor', 'typical', 'at_buy', 'at_sell', 'knife']);
    }

    /**
     * @param  array{kind: string, item_id: int, name: string, floor: ?int, target: ?int}  $a
     * @return array<string, mixed>
     */
    private function alert(array $a): array
    {
        return Arr::only($a, ['kind', 'item_id', 'name', 'floor', 'target']);
    }
}
