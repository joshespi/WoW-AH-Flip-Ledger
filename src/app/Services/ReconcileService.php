<?php

namespace App\Services;

use App\Models\Trade;
use App\Models\User;
use App\Support\Bulk;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TSM's buy log is unreliable for commodity lots, so its rows are only suggestions
 * (`tsm_buy_candidates`). A pending candidate is hidden once the user has a buy of the same item
 * and quantity within WINDOW seconds of it. One whose quantity is exactly twice a nearby buy's
 * is shown, flagged as a likely double count. A CSV-imported buy (minute resolution, lots merged)
 * covers any candidate of the same item and about the same unit price within the window, whatever
 * the quantity: the import and TSM's log describe the same purchases. Ignoring is remembered by
 * the candidate's status.
 */
class ReconcileService
{
    public const WINDOW = 600;

    public const LIMIT = 200;

    /** Candidates read per query while looking for LIMIT unmatched ones. */
    private const PAGE = 500;

    /** `tsm_buy_candidates.status` values. */
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const IGNORED = 'ignored';

    /** Unit price tolerance for a CSV buy to cover a candidate. */
    private const CSV_PRICE_TOLERANCE = 0.10;

    /**
     * Newest first, capped at LIMIT.
     *
     * @return list<array{id: int, item_id: int, name: ?string, qty: int, cost: int, at: int, double: bool}>
     */
    public function pending(User $user): array
    {
        $out = [];
        // The user's trades of each item seen so far, as [time, qty, unit price, source].
        $trades = [];

        // A long TSM history has many candidates, mostly hidden by matching buys, so they are read
        // a page at a time until LIMIT are shown, with trades loaded only for the items on each page.
        DB::table('tsm_buy_candidates')
            ->leftJoin('items', 'items.id', '=', 'tsm_buy_candidates.item_id')
            ->ownedBy($user, 'tsm_buy_candidates.user_id')->where('status', self::PENDING)
            ->orderByDesc('at')->orderByDesc('tsm_buy_candidates.id')
            ->select('tsm_buy_candidates.id', 'tsm_buy_candidates.item_id', 'qty', 'cost_copper', 'at', 'items.name')
            ->chunk(self::PAGE, function ($page) use ($user, &$out, &$trades) {
                $this->loadTrades($user, $page->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->all(), $trades);

                return $this->collect($page, $trades, $out);
            });

        return $out;
    }

    /**
     * @param  list<int>  $itemIds
     * @param  array<int, list<array{int, int, float, string}>>  $trades
     */
    private function loadTrades(User $user, array $itemIds, array &$trades): void
    {
        $new = array_values(array_diff($itemIds, array_keys($trades)));

        if ($new === []) {
            return;
        }

        $trades += array_fill_keys($new, []);

        foreach (DB::table('trades')->ownedBy($user)->whereIn('item_id', $new)
            ->select('item_id', 'traded_at', 'qty', 'cost_copper', 'source')->cursor() as $t) {
            $trades[(int) $t->item_id][] = [Utc::ts($t->traded_at), (int) $t->qty, (int) $t->cost_copper / max(1, (int) $t->qty), $t->source];
        }
    }

    /**
     * Adds the page's unmatched candidates to $out; false once LIMIT is reached (stops the paging).
     *
     * @param  iterable<object>  $page
     * @param  array<int, list<array{int, int, float, string}>>  $trades
     * @param  list<array<string, mixed>>  $out
     */
    private function collect(iterable $page, array $trades, array &$out): bool
    {
        foreach ($page as $c) {
            $at = Utc::ts($c->at);
            $qty = (int) $c->qty;
            $unit = (int) $c->cost_copper / max(1, $qty);
            $double = false;
            $matched = false;

            foreach ($trades[(int) $c->item_id] ?? [] as [$when, $q, $tradeUnit, $source]) {
                if (abs($when - $at) > self::WINDOW) {
                    continue;
                }

                $matched = $matched || $q === $qty
                    || ($source === 'csv' && abs($tradeUnit - $unit) <= $unit * self::CSV_PRICE_TOLERANCE);
                $double = $double || $q * 2 === $qty;
            }

            if ($matched) {
                continue;
            }

            $out[] = [
                'id' => (int) $c->id,
                'item_id' => (int) $c->item_id,
                'name' => $c->name,
                'qty' => $qty,
                'cost' => (int) $c->cost_copper,
                'at' => $at,
                'double' => $double,
            ];

            if (count($out) >= self::LIMIT) {
                return false;
            }
        }

        return true;
    }

    /**
     * Records the candidate as a buy, with the TSM values unless the user corrected them.
     *
     * @throws InvalidArgumentException when the values are implausible or it was already handled
     */
    public function accept(User $user, int $id, ?int $qty = null, ?int $cost = null, ?int $ts = null): Trade
    {
        return DB::transaction(function () use ($user, $id, $qty, $cost, $ts) {
            $c = DB::table('tsm_buy_candidates')->ownedBy($user)->where('status', self::PENDING)
                ->where('id', $id)->lockForUpdate()->first();

            if ($c === null) {
                throw new InvalidArgumentException('That suggestion was already handled.');
            }

            $trade = app(TradesService::class)->add(
                $user, (int) $c->item_id, $qty ?? (int) $c->qty, $cost ?? (int) $c->cost_copper,
                $ts ?? Utc::ts($c->at), null, 'tsm', $c->tsm_ref,
            );

            DB::table('tsm_buy_candidates')->where('id', $c->id)->update(['status' => self::ACCEPTED]);

            return $trade;
        });
    }

    /**
     * Accepts each candidate with its TSM values. One that is implausible or already handled is
     * skipped without stopping the rest.
     *
     * @param  list<int>  $ids
     * @return array{accepted: int, skipped: int}
     */
    public function acceptMany(User $user, array $ids): array
    {
        $ids = array_unique($ids);

        // One commit for the batch; each accept() is a savepoint, so a skipped row rolls back alone.
        $accepted = DB::transaction(function () use ($user, $ids) {
            $n = 0;

            foreach ($ids as $id) {
                try {
                    $this->accept($user, $id);
                    $n++;
                } catch (InvalidArgumentException) {
                }
            }

            return $n;
        });

        return ['accepted' => $accepted, 'skipped' => count($ids) - $accepted];
    }

    /**
     * @param  list<int>  $ids
     * @return int how many were still pending and are now ignored
     */
    public function ignoreMany(User $user, array $ids): int
    {
        $n = 0;

        foreach (array_chunk(array_unique($ids), Bulk::CHUNK) as $chunk) {
            $n += DB::table('tsm_buy_candidates')->ownedBy($user)->where('status', self::PENDING)
                ->whereIn('id', $chunk)->update(['status' => self::IGNORED]);
        }

        return $n;
    }

    public function ignore(User $user, int $id): void
    {
        $this->ignoreMany($user, [$id]);
    }
}
