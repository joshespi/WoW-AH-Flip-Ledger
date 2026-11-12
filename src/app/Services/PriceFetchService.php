<?php

namespace App\Services;

use App\Models\FetchRun;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardException;
use App\Services\Blizzard\CommoditiesParser;
use App\Support\Bulk;
use App\Support\ItemName;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls Blizzard's region-wide commodity listings and stores one snapshot per Blizzard update.
 *
 * - Conditional: sends If-Modified-Since, and also dedupes on Last-Modified itself, so a server
 *   that ignores the header can't produce duplicate snapshots.
 * - All or nothing: the response is parsed completely before anything is written, so a failed
 *   or truncated download leaves the last good data untouched.
 * - Every attempt is recorded in fetch_runs; failures never throw out of fetch().
 */
class PriceFetchService
{
    public function __construct(
        private readonly BlizzardClient $client,
        private readonly CommoditiesParser $parser,
    ) {}

    public function fetch(string $region, bool $force = false): FetchRun
    {
        // A run that died mid-way would otherwise stay "running" forever.
        FetchRun::inRegion($region)->where('status', FetchRun::STATUS_RUNNING)
            ->where('started_at', '<', now()->subHour())
            ->update(['status' => FetchRun::STATUS_FAILED, 'error' => 'Interrupted before it finished.', 'finished_at' => now()]);

        $run = FetchRun::create(['region' => $region, 'started_at' => now(), 'status' => FetchRun::STATUS_RUNNING]);
        $tmp = tempnam(sys_get_temp_dir(), 'wfl-commodities-');

        if ($tmp === false) {
            return $this->finish($run, FetchRun::STATUS_FAILED, error: 'Could not create a temporary file for the download.');
        }

        try {
            $download = $this->client->commodities($region, $tmp, $force ? null : $this->lastModified($region));

            if ($download->notModified) {
                return $this->finish($run, FetchRun::STATUS_UNCHANGED, lastModified: $download->lastModified);
            }

            // Blizzard's own update time identifies the snapshot; fall back to now if it's missing.
            $takenAt = $download->lastModified ?? time();
            $taken = Utc::sql($takenAt);

            if (! $force && DB::table('price_snapshots')->where('region', $region)->where('taken_at', $taken)->exists()) {
                return $this->finish($run, FetchRun::STATUS_UNCHANGED, lastModified: $takenAt);
            }

            $items = $this->parser->aggregate($tmp);

            DB::transaction(function () use ($region, $taken, $items) {
                $this->storeSnapshot($region, $taken, $items);
                $this->registerItems(array_keys($items));
            });

            return $this->finish($run, FetchRun::STATUS_OK, count($items), array_sum(array_column($items, 'auctions')), $takenAt);
        } catch (BlizzardException $e) {
            return $this->finish($run, FetchRun::STATUS_FAILED, error: $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->finish($run, FetchRun::STATUS_FAILED, error: 'Unexpected error while fetching prices (see the application log).');
        } finally {
            @unlink($tmp);
        }
    }

    private function lastModified(string $region): ?int
    {
        $last = FetchRun::inRegion($region)->succeeded()->whereNotNull('last_modified')->max('last_modified');

        return $last === null ? null : Utc::ts($last);
    }

    /**
     * @param  array<int, array{floor: int, quantity: int, auctions: int}>  $items
     */
    private function storeSnapshot(string $region, string $taken, array $items): void
    {
        $rows = [];

        foreach ($items as $id => $a) {
            $rows[] = [
                'region' => $region,
                'taken_at' => $taken,
                'item_id' => $id,
                'floor' => $a['floor'],
                'quantity' => $a['quantity'],
                'num_auctions' => $a['auctions'],
            ];
        }

        Bulk::insertOrIgnore('price_snapshots', $rows);
    }

    /**
     * Make sure every listed item exists in the catalog and is marked a commodity. New ones have no
     * name yet; the TSM upload or the Blizzard item lookup fills it in.
     *
     * @param  list<int>  $ids
     */
    private function registerItems(array $ids): void
    {
        $now = now();

        foreach (array_chunk($ids, Bulk::CHUNK) as $chunk) {
            DB::table('items')->insertOrIgnore(array_map(
                static fn (int $id): array => ['id' => $id, 'name' => null, 'name_source' => ItemName::SOURCE_NONE, 'is_commodity' => true, 'created_at' => $now, 'updated_at' => $now],
                $chunk,
            ));

            DB::table('items')->whereIn('id', $chunk)->where('is_commodity', false)->update(['is_commodity' => true, 'updated_at' => $now]);
        }
    }

    private function finish(FetchRun $run, string $status, int $items = 0, int $auctions = 0, ?int $lastModified = null, ?string $error = null): FetchRun
    {
        $run->update([
            'status' => $status,
            'items' => $items,
            'auctions' => $auctions,
            'last_modified' => $lastModified === null ? null : Utc::sql($lastModified),
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'finished_at' => now(),
        ]);

        return $run;
    }
}
