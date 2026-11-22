<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A recipe the user paid for. Its cost is a realized loss on the item it makes; crafts can link to it for ROI. */
#[Fillable(['user_id', 'game', 'item_id', 'name', 'cost_copper', 'bought_at', 'note'])]
class Recipe extends Model
{
    use OwnedByUser;
}
