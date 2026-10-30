<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * For models of per-user tables: `Trade::ownedBy($user)`. Every query on a user's data starts
 * here (raw `DB::table()` queries use the same name through a builder macro), so tenant
 * scoping is one thing to grep for instead of a `where` each author must remember.
 */
trait OwnedByUser
{
    public function scopeOwnedBy(Builder $query, User|int $user): void
    {
        $query->where($this->qualifyColumn('user_id'), $user instanceof User ? $user->id : $user);
    }
}
