<?php

namespace App\Services;

use App\Models\FetchRun;
use App\Models\Upload;
use App\Models\User;
use App\Support\Game;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;

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
        public bool $scans = false,
    ) {}

    public static function for(User $user, string $game = Game::RETAIL): self
    {
        $upload = $user->uploads()->succeeded()->newest()->first(['file_mtime', 'received_at']);

        if (! Game::hasMarket($game)) {
            // No fetch runs: the user's newest price scan. Scans are only as fresh as the last auction
            // house visit, so they are never flagged stale.
            $scan = DB::table('scan_prices')->ownedBy($user)->where('region', $user->region)->where('game', $game)->max('taken_at');

            return new self(
                ($upload?->file_mtime ?? $upload?->received_at)?->getTimestamp(),
                $scan === null ? null : Utc::ts($scan),
                null,
                false,
                $user->stale_minutes,
                now()->getTimestamp(),
                scans: true,
            );
        }

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
            || ($this->pricesFetchedAt !== null && $this->now - $this->pricesFetchedAt > FetchRun::staleAfter());
    }

    /** "just now", "5 min ago", "3 h ago", "2 d ago": coarse on purpose. */
    public function ago(int $ts): string
    {
        $seconds = max(0, $this->now - $ts);

        return $seconds < 90 ? __('just now') : __(':age ago', ['age' => match (true) {
            $seconds < Utc::HOUR => intdiv($seconds, 60).' min',
            $seconds < Utc::DAY => intdiv($seconds, Utc::HOUR).' h',
            default => intdiv($seconds, Utc::DAY).' d',
        }]);
    }
}
