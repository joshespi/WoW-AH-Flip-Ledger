<?php

namespace App\Services;

use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\Bounds;
use App\Support\ItemName;
use App\Support\MarketStats;
use App\Support\Money;
use App\Support\Num;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A user's watchlist and the market numbers shown beside it. Every write is scoped to the user.
 * Derived numbers (recent, typical, sold/day, the knife flag) are null/false until the item has
 * ledger.prices.min_days of history; the floor is always exact.
 */
class WatchlistService
{
    public const MAX_ITEMS = 500;

    /**
     * Ordered by group (ungrouped last), then the user's own order.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(User $user): array
    {
        return DB::table('watchlist_items as w')
            ->leftJoin('items', 'items.id', '=', 'w.item_id')
            ->leftJoin('item_stats as s', fn ($j) => $j->on('s.item_id', '=', 'w.item_id')->where('s.region', $user->region))
            ->ownedBy($user, 'w.user_id')
            ->orderByRaw('w.group_name is null')->orderBy('w.group_name')->orderBy('w.sort')->orderBy('w.id')
            ->get(['w.id', 'w.item_id', 'w.group_name', 'w.target_buy_copper', 'w.target_sell_copper', 'w.notes',
                'items.name', 's.floor', 's.recent', 's.typical', 's.sold_per_day_est', 's.days_of_data'])
            ->map(function ($r) {
                $ready = MarketStats::ready($r);
                $floor = Num::intOrNull($r->floor);
                $buy = Num::intOrNull($r->target_buy_copper);
                $sell = Num::intOrNull($r->target_sell_copper);
                $recent = MarketStats::derived($r, 'recent');
                $typical = MarketStats::derived($r, 'typical');

                return [
                    'id' => (int) $r->id,
                    'item_id' => (int) $r->item_id,
                    'name' => ItemName::display($r->name, (int) $r->item_id),
                    'group' => $r->group_name,
                    'buy' => $buy,
                    'sell' => $sell,
                    'notes' => $r->notes,
                    'floor' => $floor,
                    'recent' => $recent,
                    'typical' => $typical,
                    'sold' => MarketStats::derived($r, 'sold_per_day_est'),
                    'days' => (int) ($r->days_of_data ?? 0),
                    'ready' => $ready,
                    'at_buy' => $floor !== null && $buy !== null && $floor <= $buy,
                    'at_sell' => $floor !== null && $sell !== null && $floor >= $sell,
                    'knife' => FallingKnife::is($floor, $recent, $typical),
                ];
            })->all();
    }

    /** @throws InvalidArgumentException */
    public function add(User $user, int $itemId, ?string $group = null): WatchlistItem
    {
        if (! Bounds::validItemId($itemId)) {
            throw new InvalidArgumentException('That item id is out of range.');
        }

        $held = WatchlistItem::ownedBy($user)->toBase()->selectRaw('count(*) as n, max(sort) as last')->first();

        if ($held->n >= self::MAX_ITEMS) {
            throw new InvalidArgumentException('Your watchlist is full ('.self::MAX_ITEMS.' items).');
        }

        // The unique (user_id, item_id) index decides duplicates, so a double click can't race it.
        try {
            return WatchlistItem::create([
                'user_id' => $user->id,
                'item_id' => $itemId,
                'group_name' => $this->group($group),
                'sort' => (int) $held->last + 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('That item is already on your watchlist.');
        }
    }

    /**
     * Targets are per-unit gold text ("12.5", "12g 50s"); blank clears one.
     *
     * @throws InvalidArgumentException
     */
    public function update(User $user, int $id, string $buy, string $sell, ?string $group, ?string $notes): WatchlistItem
    {
        $item = WatchlistItem::ownedBy($user)->findOrFail($id);
        $item->update([
            'target_buy_copper' => $this->target($buy),
            'target_sell_copper' => $this->target($sell),
            'group_name' => $this->group($group),
            'notes' => Bounds::note($notes),
        ]);

        return $item;
    }

    public function remove(User $user, int $id): void
    {
        WatchlistItem::ownedBy($user)->findOrFail($id)->delete();
    }

    /** Swaps with the neighbour in the same group; no-op at the edge. */
    public function move(User $user, int $id, int $direction): void
    {
        DB::transaction(function () use ($user, $id, $direction) {
            $item = WatchlistItem::ownedBy($user)->lockForUpdate()->findOrFail($id);

            $peers = WatchlistItem::ownedBy($user)
                ->when($item->group_name === null, fn ($q) => $q->whereNull('group_name'), fn ($q) => $q->where('group_name', $item->group_name))
                ->orderBy('sort')->orderBy('id')->get();

            $i = $peers->search(fn ($p) => $p->id === $item->id);
            $j = $i + ($direction < 0 ? -1 : 1);

            if (! isset($peers[$j])) {
                return;
            }

            // Renumber so ties and gaps cannot make the swap a no-op.
            $order = $peers->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];

            foreach ($order as $n => $p) {
                if ((int) $p->sort !== $n) {
                    $p->update(['sort' => $n]);
                }
            }
        });
    }

    private function group(?string $group): ?string
    {
        return Bounds::text($group, 64);
    }

    private function target(string $text): ?int
    {
        if (trim($text) === '') {
            return null;
        }

        try {
            $c = Money::parseGold($text);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('"'.Str::limit(trim($text), 30).'" is not a gold amount.');
        }

        if (! Bounds::validPrice($c)) {
            throw new InvalidArgumentException('Targets must be between 0 and '.Bounds::gold(Bounds::MAX_PRICE).' gold per unit.');
        }

        return $c;
    }
}
