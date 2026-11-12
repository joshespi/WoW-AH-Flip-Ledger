<?php

namespace App\Support;

use App\Models\Trade;

/**
 * One item's stock at weighted average cost: the buy, sale and correction math behind
 * LedgerService and ItemDetailService. Integer copper throughout; callers order the events.
 */
final class Holding
{
    public int $held = 0;

    public int $cost = 0;

    public function buy(int $qty, int $cost): void
    {
        $this->held += $qty;
        $this->cost += $cost;
    }

    /**
     * Sells up to $qty for $total (the whole lot's net). Units beyond the holding are the caller's
     * to report (own stock).
     *
     * @return array{int, int, int} [units from the holding, their share of $total, their cost]
     */
    public function sell(int $qty, int $total): array
    {
        [$m, $cogs] = $this->take($qty);

        return [$m, Num::mulDiv($total, $m, $qty), $cogs];
    }

    /**
     * Removes up to $qty by a correction (Trade::CORRECTIONS). Proceeds (none for a write-off)
     * count for the units actually held, like a sale's revenue.
     *
     * @return ?int realized gain, or null when the side books no P/L
     */
    public function correct(string $side, int $qty, int $proceeds): ?int
    {
        [$m, $removed] = $this->take($qty);

        return Trade::booksPnl($side) ? Num::mulDiv($proceeds, $m, $qty) - $removed : null;
    }

    public function avg(): ?float
    {
        return $this->held > 0 ? $this->cost / $this->held : null;
    }

    /**
     * Takes up to $qty units out at average cost.
     *
     * @return array{int, int} [units taken, their cost]
     */
    private function take(int $qty): array
    {
        $m = min($qty, $this->held);
        $cost = $this->held > 0 ? Num::mulDiv($this->cost, $m, $this->held) : 0;

        $this->held -= $m;
        $this->cost -= $cost;

        return [$m, $cost];
    }
}
