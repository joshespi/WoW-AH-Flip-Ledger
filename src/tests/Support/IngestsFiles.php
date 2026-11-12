<?php

namespace Tests\Support;

use App\Models\Upload;
use App\Models\User;
use App\Services\IngestService;
use App\Support\Game;
use App\Support\TsmSpan;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;

/**
 * Ingest helpers for tests that hold the user in `$this->user`: addon and TSM files through
 * IngestService, row counts, the TSM span, and the uploader endpoint.
 */
trait IngestsFiles
{
    use CallsApi;

    protected const T = AddonFile::T0;

    protected const DAY = Utc::DAY;

    /**
     * @param  list<string>  $events
     * @param  list<string>  $inv
     */
    protected function addon(array $events, array $inv = [], ?int $last = null, string $game = Game::RETAIL, ?string $account = null): Upload
    {
        return app(IngestService::class)->ingest(
            $this->user,
            AddonFile::make($events, $inv, last: $last ?? self::T + 10 * self::DAY),
            'uploader',
            self::T + 10 * self::DAY,
            wowAccount: $account,
            game: $game,
        );
    }

    /**
     * @param  list<array{string, int, int, int, string}>  $sales
     * @param  array<string, int>  $inventory
     */
    protected function tsm(array $sales, ?int $mtime = self::T + 20 * self::DAY, array $inventory = [], string $game = Game::RETAIL, ?string $account = null): Upload
    {
        return app(IngestService::class)->ingest($this->user, TsmFile::make(sales: $sales, inventory: $inventory), 'uploader', $mtime, wowAccount: $account, game: $game);
    }

    protected function rowsOf(string $table, ?string $origin = null, ?string $game = null, ?User $user = null): int
    {
        return DB::table($table)->ownedBy($user ?? $this->user)
            ->when($origin, fn ($q) => $q->where('origin', $origin))
            ->when($game, fn ($q) => $q->inGame($game))
            ->count();
    }

    /** @return array{0: string, 1: string}|null */
    protected function span(string $game = Game::RETAIL, string $account = ''): ?array
    {
        return TsmSpan::get($this->user, $game, $account);
    }
}
