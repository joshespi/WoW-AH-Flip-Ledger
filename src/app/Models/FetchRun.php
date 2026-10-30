<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['region', 'started_at', 'finished_at', 'status', 'items', 'auctions', 'last_modified', 'error'])]
class FetchRun extends Model
{
    public $timestamps = false;

    public const STATUS_OK = 'ok';

    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RUNNING = 'running';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_modified' => 'datetime',
        ];
    }

    /** The newest run in a region that gave us usable prices (or confirmed there were none new). */
    public static function latestGood(string $region): ?self
    {
        return static::where('region', $region)->succeeded()->latest('started_at')->first();
    }

    /** The newest run in a region that has ended, whatever its outcome. */
    public static function latestFinished(string $region): ?self
    {
        return static::where('region', $region)->where('status', '!=', self::STATUS_RUNNING)->latest('started_at')->first();
    }

    /**
     * [latest finished, latest good] for a region. The common case (the newest run succeeded) is one query.
     *
     * @return array{0: ?self, 1: ?self}
     */
    public static function latestPair(string $region): array
    {
        $last = static::latestFinished($region);

        if ($last === null) {
            return [null, null];
        }

        return [$last, in_array($last->status, [self::STATUS_OK, self::STATUS_UNCHANGED], true) ? $last : static::latestGood($region)];
    }

    /** Runs that gave us a usable snapshot, or confirmed there was nothing new. */
    public function scopeSucceeded(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_OK, self::STATUS_UNCHANGED]);
    }
}
