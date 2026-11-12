<?php

namespace App\Services;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardException;
use App\Support\ItemName;
use Illuminate\Support\Facades\DB;

/**
 * Fills the item catalog from Blizzard's item API: commodities with no name first, then ones
 * that only have a TSM-sourced name (Blizzard's wins). Each item is looked up once; a 404 is
 * remembered through fetched_at so it isn't retried every run. Calls are paced well under
 * Blizzard's 100 requests/second limit, and any server-side failure ends the run early (the rest
 * wait for the next one).
 */
class ItemNameFetcher
{
    public function __construct(private readonly BlizzardClient $client) {}

    /**
     * @return array{updated: int, not_found: int, stopped: ?string}
     */
    public function fetch(string $region, int $limit, ?int $pauseMs = null): array
    {
        $pauseMs ??= (int) config('ledger.prices.names_pause_ms');

        $ids = DB::table('items')
            ->where('is_commodity', true)
            ->whereNull('fetched_at')
            ->where(fn ($q) => $q->whereNull('name')->orWhere('name_source', '!=', ItemName::SOURCE_BLIZZARD))
            ->orderByRaw('(name IS NULL) DESC')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $result = ['updated' => 0, 'not_found' => 0, 'stopped' => null];

        foreach ($ids as $id) {
            try {
                $item = $this->client->item((int) $id, $region);
            } catch (BlizzardException $e) {
                $result['stopped'] = $e->getMessage();
                break;
            }

            $now = now();

            if ($item === null) {
                DB::table('items')->where('id', $id)->update(['fetched_at' => $now]);
                $result['not_found']++;
            } else {
                $name = $item['name'] === null ? null : ItemName::sanitize($item['name']);

                $update = ['fetched_at' => $now, 'updated_at' => $now, 'quality' => $item['quality']];

                if ($name !== null) {
                    $update += ['name' => $name, 'name_source' => ItemName::SOURCE_BLIZZARD];
                }

                DB::table('items')->where('id', $id)->update($update);
                $result['updated']++;
            }

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        return $result;
    }
}
