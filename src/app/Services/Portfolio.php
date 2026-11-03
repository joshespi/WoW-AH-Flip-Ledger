<?php

namespace App\Services;

/**
 * Everything the dashboard and positions screens show for one user.
 *
 * `summary` money is copper: realized (net of the AH cut), stock_cost, stock_at_floor (held
 * units at the current floor, net of the cut, for items with a price), unrealized (that
 * minus the cost of the same items), own_stock_sales, unpriced_items (held items with no
 * floor yet, left out of the two floor-based totals).
 */
final readonly class Portfolio
{
    /**
     * @param  list<PositionRow>  $positions  open positions first (largest cost basis first), then closed ones
     * @param  array{realized: int, stock_cost: int, stock_at_floor: int, unrealized: int, own_stock_sales: int, unpriced_items: int}  $summary
     * @param  array{name: string, pct: float}|null  $concentration  set when one item is over the configured share of stock cost
     * @param  list<array{type: string, ts: int, item_id: int, name: string, qty: int, unit: int, total: int}>  $recent
     * @param  list<array{int, int}>  $realizedEvents  [epoch ts, copper] per sale or write-off; sums to summary.realized
     */
    public function __construct(
        public array $positions,
        public array $summary,
        public ?array $concentration,
        public array $recent,
        public array $realizedEvents = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->positions === [];
    }

    /**
     * @return list<PositionRow>
     */
    public function open(): array
    {
        return array_values(array_filter($this->positions, fn (PositionRow $p) => ! $p->closed()));
    }
}
