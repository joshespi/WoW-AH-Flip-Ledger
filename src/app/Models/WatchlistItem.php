<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'item_id', 'group_name', 'target_buy_copper', 'target_sell_copper', 'notes', 'sort'])]
class WatchlistItem extends Model
{
    use OwnedByUser;
}
