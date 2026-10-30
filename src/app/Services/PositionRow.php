<?php

namespace App\Services;

/**
 * One item in a user's ledger, joined with current market numbers. Money is copper; averages
 * are floats (copper per unit). The floor and everything valued at it are null for closed
 * positions. Derived market fields (recent, typical, hist60, sold/day and everything computed
 * from them) are null until the item has enough price history; `statsReady` says which case.
 */
final readonly class PositionRow
{
    public function __construct(
        public int $itemId,
        public string $name,
        public int $held,
        public ?float $avgCost,
        public ?float $breakEven,
        public ?float $targetSell,
        public int $soldQty,
        public int $realized,
        public int $ownRevenue,
        public int $costBasis,
        public ?int $floor,
        public ?int $recent,
        public ?int $typical,
        public ?int $hist60,
        public ?int $soldPerDay,
        public int $daysOfData,
        public bool $statsReady,
        /** floor / break-even - 1 (0.05 = 5% over break-even). */
        public ?float $floorVsBreakEven,
        /** floor / typical price; only when stats are ready. */
        public ?float $floorVsTypical,
        public ?int $valueAtFloor,
        public ?int $unrealized,
        public ?float $daysToSell,
        /** 'profit' | 'underwater' | null (nothing held, or no price yet) */
        public ?string $status,
        /** 'below' | 'above' | null */
        public ?string $market,
        /** Units held beyond the latest TSM inventory snapshot (likely sales TSM has not logged yet); null when none or no snapshot. */
        public ?int $unaccounted = null,
    ) {}

    public function closed(): bool
    {
        return $this->held === 0;
    }
}
