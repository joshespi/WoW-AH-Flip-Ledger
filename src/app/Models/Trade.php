<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'item_id', 'traded_at', 'qty', 'cost_copper', 'side', 'note', 'source', 'tsm_ref', 'import_key'])]
class Trade extends Model
{
    use OwnedByUser;

    public const SIDE_BUY = 'buy';

    /** Removes units at average cost with no P/L: the ledger counted stock the user never held. */
    public const SIDE_ADJUST = 'adjust';

    /** Removes units at average cost and books that cost as a realized loss (vendored, used, lost). */
    public const SIDE_WRITEOFF = 'writeoff';

    public const CORRECTIONS = [self::SIDE_ADJUST, self::SIDE_WRITEOFF];

    /** How a correction reads on screen; static because list pages read rows, not models. */
    public static function correctionLabel(string $side): string
    {
        return $side === self::SIDE_WRITEOFF ? __('Written off') : __('Corrected');
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
