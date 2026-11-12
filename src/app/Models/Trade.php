<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'game', 'item_id', 'traded_at', 'qty', 'cost_copper', 'side', 'note', 'source', 'tsm_ref', 'import_key'])]
class Trade extends Model
{
    use OwnedByUser;

    public const SIDE_BUY = 'buy';

    // Where a row came from (`source`).
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_PASTE = 'paste';

    public const SOURCE_TSM = 'tsm';

    public const SOURCE_CSV = 'csv';

    /** Removes units at average cost with no P/L: the ledger counted stock the user never held. */
    public const SIDE_ADJUST = 'adjust';

    /** Removes units at average cost and books that cost as a realized loss (vendored, used, lost). */
    public const SIDE_WRITEOFF = 'writeoff';

    /** Sold to a vendor: removes units at average cost; `cost_copper` is the gold received (total), so P/L = proceeds - cost. */
    public const SIDE_VENDOR = 'vendor';

    /** Every side that removes stock, in replay order at the same time (after buys and sales). */
    public const CORRECTIONS = [self::SIDE_ADJUST, self::SIDE_WRITEOFF, self::SIDE_VENDOR];

    /** What the Correct holdings form books (no proceeds); a vendor sale goes through TradesService::vendor(). */
    public const HOLDING_CORRECTIONS = [self::SIDE_ADJUST, self::SIDE_WRITEOFF];

    /** A correction's place in CORRECTIONS: its replay order in LedgerService and ItemDetailService. */
    public static function correctionRank(string $side): int
    {
        return (int) array_search($side, self::CORRECTIONS, true);
    }

    /** Whether a correction books realized P/L (proceeds, if any, minus the removed cost); an adjust does not. */
    public static function booksPnl(string $side): bool
    {
        return $side !== self::SIDE_ADJUST;
    }

    /** Gold a correction brought in, or null when its side has none (adjust and writeoff store 0). */
    public static function proceeds(string $side, int $costCopper): ?int
    {
        return $side === self::SIDE_VENDOR ? $costCopper : null;
    }

    /** How a correction reads on screen; static because list pages read rows, not models. */
    public static function correctionLabel(string $side): string
    {
        return match ($side) {
            self::SIDE_WRITEOFF => __('Written off'),
            self::SIDE_VENDOR => __('Vendored'),
            default => __('Corrected'),
        };
    }

    protected function casts(): array
    {
        return [
            'traded_at' => 'datetime',
            'qty' => 'integer',
            'cost_copper' => 'integer',
        ];
    }
}
