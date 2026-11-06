<?php

namespace Tests\Support;

use App\Models\Upload;
use App\Models\User;
use App\Services\IngestService;
use App\Support\Game;
use App\Support\TsmSpan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Ingest helpers for tests that hold the user in `$this->user`: addon and TSM files through
 * IngestService, row counts, the TSM span, and the uploader endpoint.
 */
trait IngestsFiles
{
    protected const T = AddonFile::T0;

    protected const DAY = 86400;

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

    /** @param  array<string, string>  $headers */
    protected function postIngest(string $body, string $token, array $headers = []): TestResponse
    {
        // The auth manager caches the resolved user for the life of the app; tests that switch
        // tokens mid-test need a fresh guard.
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/v1/ingest', [], [], [], $this->transformHeadersToServerVars($headers + [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/octet-stream',
        ]), $body);
    }
}
