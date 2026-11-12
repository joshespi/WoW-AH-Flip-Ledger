<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'game', 'source', 'client_version', 'wow_account', 'file_mtime', 'received_at', 'bytes', 'status', 'error', 'counts'])]
class Upload extends Model
{
    use OwnedByUser;

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_UPLOADER = 'uploader';

    /** Not stored: the failure was temporary (busy, database error), so the same file may succeed later. */
    public bool $retryable = false;

    /** Not stored: set when refused as another game's file (UploadGameCheck); true when that was only a guess the user may override. */
    public ?bool $wrongGameConfirmable = null;

    protected function casts(): array
    {
        return [
            'file_mtime' => 'datetime',
            'received_at' => 'datetime',
            'counts' => 'array',
        ];
    }

    /** Newest first; the id breaks ties within the same second. */
    public function scopeNewest(Builder $query): void
    {
        $query->latest('received_at')->latest('id');
    }

    /** Oldest first; the id breaks ties within the same second. */
    public function scopeOldestFirst(Builder $query): void
    {
        $query->oldest('received_at')->oldest('id');
    }

    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', self::STATUS_OK);
    }

    public function scopeFailures(Builder $query): void
    {
        $query->where('status', self::STATUS_FAILED);
    }

    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * "N sales, N expired, N cancelled" from the stored counts, or null when there are none.
     *
     * @param  array<string, mixed>|null  $counts
     */
    public static function summary(?array $counts): ?string
    {
        return $counts ? __(':sales sales, :expired expired, :cancelled cancelled', [
            'sales' => number_format($counts['sales']['new'] ?? 0),
            'expired' => number_format($counts['expired']['new'] ?? 0),
            'cancelled' => number_format($counts['cancelled']['new'] ?? 0),
        ]) : null;
    }
}
