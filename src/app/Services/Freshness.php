<?php

namespace App\Services;

use App\Models\FetchRun;
use App\Models\Upload;
use App\Models\User;

/**
 * How old the data behind the screens is, for the page header.
 *
 * TSM only writes its file on /reload or logout, so "TSM saved" is the file's own time (as the
 * uploader or browser reported it), falling back to when we received the upload. It goes
 * stale after the user's `stale_minutes`.
 *
 * "Prices" is Blizzard's own snapshot time from the newest successful fetch run (falling back
 * to when that run finished). Prices are stale when that run is older than
 * `ledger.prices.stale_hours`, or when the most recent fetch failed.
 */
final readonly class Freshness
{
    public function __construct(
        public ?int $tsmSavedAt,
        public ?int $pricesAt,
        public ?int $pricesFetchedAt,
        public bool $lastFetchFailed,
        public int $staleMinutes,
        public int $now,
    ) {}

    public static function for(User $user): self
    {
        $upload = $user->uploads()->succeeded()->newest()->first(['file_mtime', 'received_at']);

        // Uses the (region, started_at) index; item_stats would mean scanning a whole region.
        [$last, $good] = FetchRun::latestPair($user->region);
        $lastStatus = $last?->status;

        return new self(
            ($upload?->file_mtime ?? $upload?->received_at)?->getTimestamp(),
            ($good?->last_modified ?? $good?->finished_at)?->getTimestamp(),
            $good?->finished_at?->getTimestamp(),
            $lastStatus === FetchRun::STATUS_FAILED,
            $user->stale_minutes,
            now()->getTimestamp(),
        );
    }

    public function tsmStale(): bool
    {
        return $this->tsmSavedAt !== null && $this->now - $this->tsmSavedAt > $this->staleMinutes * 60;
    }

    public function pricesStale(): bool
    {
        return $this->lastFetchFailed
            || ($this->pricesFetchedAt !== null && $this->now - $this->pricesFetchedAt > config('ledger.prices.stale_hours') * 3600);
    }

    /** "just now", "5 min ago", "3 h ago", "2 d ago": coarse on purpose. */
    public function ago(int $ts): string
    {
        $seconds = max(0, $this->now - $ts);

        return $seconds < 90 ? __('just now') : __(':age ago', ['age' => match (true) {
            $seconds < 3600 => intdiv($seconds, 60).' min',
            $seconds < 86400 => intdiv($seconds, 3600).' h',
            default => intdiv($seconds, 86400).' d',
        }]);
    }
}
