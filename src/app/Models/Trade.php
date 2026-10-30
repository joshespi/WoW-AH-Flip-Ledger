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

    public const SIDE_ADJUST = 'adjust';

    protected function casts(): array
    {
        return [
            'traded_at' => 'datetime',
            'qty' => 'integer',
            'cost_copper' => 'integer',
        ];
    }
}
