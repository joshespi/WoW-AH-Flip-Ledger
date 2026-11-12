<?php

namespace App\Services;

use App\Models\Trade;
use App\Models\User;
use App\Support\Bounds;
use App\Support\Game;
use App\Support\ItemName;
use App\Support\Money;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The user's hand-entered buys: add, edit, delete and the chat-line paste. Every write is
 * scoped to the user, so another user's trade id is simply "not found".
 */
class TradesService
{
    public function __construct(private TradesPasteParser $paste, private PositionsService $positions) {}

    /**
     * "i:242639", "242639" or an exact item name that matches exactly one item.
     *
     * @throws InvalidArgumentException
     */
    public function resolveItem(string $text): int
    {
        $text = trim($text);

        if (($id = ItemName::bareId($text)) === null) {
            $ids = ItemName::idsByName([$text])[mb_strtolower($text)] ?? [];

            if (count($ids) !== 1) {
                throw new InvalidArgumentException($ids === []
                    ? "No item named \"{$text}\" is known yet. Use its item id."
                    : "\"{$text}\" matches several items. Use its item id.");
            }

            $id = (int) $ids[0];
        }

        // Range-checked by the write that uses it.
        return $id;
    }

    /** @throws InvalidArgumentException */
    public function add(User $user, int $itemId, int $qty, int $cost, int $ts, ?string $note, string $source = Trade::SOURCE_MANUAL, ?string $tsmRef = null, string $game = Game::RETAIL): Trade
    {
        return $this->create($user, Trade::SIDE_BUY, $itemId, $qty, $cost, $ts, $note, $source, $tsmRef, $game);
    }

    /**
     * Removes qty units at average cost with no P/L: a correction for stock the log counted but
     * the user never held (see LedgerService).
     *
     * @throws InvalidArgumentException
     */
    public function adjust(User $user, int $itemId, int $qty, int $ts, ?string $note = null, string $game = Game::RETAIL): Trade
    {
        return $this->create($user, Trade::SIDE_ADJUST, $itemId, $qty, 0, $ts, $note, Trade::SOURCE_SYSTEM, null, $game);
    }

    /**
     * Sells all of $buy to a vendor at $unitProceeds copper each, at the buy's time: removed at
     * average cost, the difference is realized P/L (see LedgerService). It takes the buy's
     * tsm_ref, so delete() of the buy takes this with it.
     *
     * @throws InvalidArgumentException
     */
    public function vendor(User $user, Trade $buy, int $unitProceeds): Trade
    {
        // MAX_PRICE x MAX_QTY fits in an int, so the total can't overflow; create() bounds it.
        if (! Bounds::validPrice($unitProceeds)) {
            throw new InvalidArgumentException('Enter what the vendor paid for each, at most '.Bounds::gold(Bounds::MAX_PRICE).' gold.');
        }

        return $this->create($user, Trade::SIDE_VENDOR, (int) $buy->item_id, (int) $buy->qty, $unitProceeds * (int) $buy->qty,
            Utc::ts($buy->getRawOriginal('traded_at')), null, Trade::SOURCE_MANUAL, $buy->tsm_ref, $buy->game);
    }

    /**
     * Brings the ledger's held quantity down to $held now, by one correction ($side, one of
     * Trade::HOLDING_CORRECTIONS) for the difference. Stock can only be removed here; more stock is
     * a buy, and a vendor sale goes through vendor().
     *
     * @throws InvalidArgumentException
     */
    public function correctHeld(User $user, int $itemId, int $held, string $side, ?string $note = null, string $game = Game::RETAIL): Trade
    {
        if (! in_array($side, Trade::HOLDING_CORRECTIONS, true)) {
            throw new InvalidArgumentException('Pick what happened to the rest.');
        }

        $current = $this->positions->held($user, $itemId, $game);

        if ($current === 0) {
            throw new InvalidArgumentException('The ledger holds none of this item.');
        }

        if ($held < 0 || $held >= $current) {
            throw new InvalidArgumentException('Held must be less than '.number_format($current).'. To add stock, add a buy.');
        }

        return $this->create($user, $side, $itemId, $current - $held, 0, time(), $note, Trade::SOURCE_MANUAL, null, $game);
    }

    public function deleteCorrection(User $user, int $id): void
    {
        Trade::ownedBy($user)->whereIn('side', Trade::CORRECTIONS)->findOrFail($id)->delete();
    }

    /**
     * A vendored buy's vendor sale follows it (item, qty, time; same price each), so it never
     * sells stock the buy no longer brings in.
     *
     * @throws InvalidArgumentException
     */
    public function update(User $user, int $id, int $itemId, int $qty, int $cost, int $ts, ?string $note): Trade
    {
        $attributes = $this->attributes($itemId, $qty, $cost, $ts, $note);

        return DB::transaction(function () use ($user, $id, $attributes, $itemId, $qty, $ts) {
            $trade = Trade::ownedBy($user)->findOrFail($id);

            if ($trade->side !== Trade::SIDE_BUY) {
                throw new InvalidArgumentException('Only buys can be edited.');
            }

            if (($vendor = $this->vendorOf($user, $trade)) !== null) {
                // vendor() stored unit x qty, so this division is exact.
                $unit = Money::unit((int) $vendor->cost_copper, (int) $vendor->qty);
                $vendor->update($this->attributes($itemId, $qty, $unit * $qty, $ts, $vendor->note));
            }

            $trade->update($attributes);

            return $trade;
        });
    }

    /** A buy takes the vendor sale booked with it: left alone it would sell other stock. */
    public function delete(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $trade = Trade::ownedBy($user)->findOrFail($id);
            $this->vendorOf($user, $trade)?->delete();
            $trade->delete();
        });
    }

    /** The vendor sale booked with a reconciled buy (same tsm_ref), if any. */
    private function vendorOf(User $user, Trade $trade): ?Trade
    {
        return $trade->side === Trade::SIDE_BUY && $trade->tsm_ref !== null
            ? Trade::ownedBy($user)->inGame($trade->game)->where('side', Trade::SIDE_VENDOR)->where('tsm_ref', $trade->tsm_ref)->first()
            : null;
    }

    /**
     * Adds every "You won an auction" line in the pasted text, all or nothing. Names must match
     * one known item unless the line carries an item link. Pasted lines have no time: $ts is used.
     *
     * @return int trades added
     *
     * @throws InvalidArgumentException
     */
    public function addPasted(User $user, string $text, int $ts, string $game = Game::RETAIL): int
    {
        if (strlen($text) > 256 * 1024) {
            throw new InvalidArgumentException('That paste is too large.');
        }

        $lines = $this->paste->parse($text);

        if ($lines === []) {
            throw new InvalidArgumentException('No "You won an auction for ..." lines found.');
        }

        // A throw rolls back the lines already added.
        DB::transaction(function () use ($user, $lines, $ts, $game) {
            $ids = [];

            foreach ($lines as $l) {
                try {
                    $id = $l['id'] ?? ($ids[mb_strtolower($l['name'])] ??= $this->resolveItem($l['name']));
                    $this->add($user, $id, $l['qty'], $l['cost'], $ts, null, Trade::SOURCE_PASTE, null, $game);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException("line {$l['line']}: ".$e->getMessage());
                }
            }
        });

        return count($lines);
    }

    /** @throws InvalidArgumentException */
    private function create(User $user, string $side, int $itemId, int $qty, int $cost, int $ts, ?string $note, string $source, ?string $tsmRef = null, string $game = Game::RETAIL): Trade
    {
        return Trade::create($this->attributes($itemId, $qty, $cost, $ts, $note) + [
            'tsm_ref' => $tsmRef,
            'user_id' => $user->id,
            'game' => $game,
            'side' => $side,
            'source' => $source,
        ]);
    }

    /**
     * The columns hand entry sets, checked.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function attributes(int $itemId, int $qty, int $cost, int $ts, ?string $note): array
    {
        if (($problem = Bounds::tradeProblem($itemId, $qty, $cost, $ts)) !== null) {
            throw new InvalidArgumentException(ucfirst($problem).'.');
        }

        return ['item_id' => $itemId, 'traded_at' => Utc::sql($ts), 'qty' => $qty, 'cost_copper' => $cost, 'note' => Bounds::note($note)];
    }
}
