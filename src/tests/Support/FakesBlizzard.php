<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

trait FakesBlizzard
{
    use TempFiles;

    /** Blizzard's snapshot time in the default fake. */
    public const LAST_MODIFIED = 'Mon, 26 Oct 2026 12:00:00 GMT';

    /** The URL patterns fakeBlizzard() stubs; pass them as keys of $overrides to replace one. */
    public const TOKEN_URL = 'oauth.battle.net/*';

    public const COMMODITIES_URL = 'us.api.blizzard.com/data/wow/auctions/commodities*';

    public const ITEM_URL = 'us.api.blizzard.com/data/wow/item/*';

    protected function lastModifiedEpoch(): int
    {
        return strtotime(self::LAST_MODIFIED);
    }

    protected function blizzardConfigured(): void
    {
        config([
            'services.blizzard.client_id' => 'test-client-id',
            'services.blizzard.client_secret' => 'test-client-secret-value',
            'services.blizzard.region' => 'us',
            'ledger.prices.names_pause_ms' => 0,
        ]);

        Cache::flush();
    }

    protected function commoditiesBody(): string
    {
        return file_get_contents(__DIR__.'/../fixtures/blizzard/commodities.json');
    }

    /**
     * Fakes the token, commodities and item endpoints. $overrides are checked first, so a test can
     * replace any one of them: [self::COMMODITIES_URL => ...].
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function fakeBlizzard(array $overrides = []): void
    {
        $this->blizzardConfigured();

        Http::fake($overrides + [
            self::TOKEN_URL => Http::response(['access_token' => 'tok-abc', 'token_type' => 'bearer', 'expires_in' => 86399]),
            // A closure, so every request gets a fresh response: a Http::response() object's body can
            // only be read once, and a reused one would arrive empty the second time.
            self::COMMODITIES_URL => fn () => Http::response($this->commoditiesBody(), 200, ['Last-Modified' => self::LAST_MODIFIED]),
            self::ITEM_URL => function (Request $request) {
                $id = (int) basename(parse_url($request->url(), PHP_URL_PATH));
                $names = [238371 => ['Arcane Wyrmfish', 'EPIC'], 242639 => ['Practically Pork', 'COMMON'], 238365 => ["Sin'dorei Swarmer", 'UNCOMMON']];

                return isset($names[$id])
                    ? Http::response(['id' => $id, 'name' => $names[$id][0], 'quality' => ['type' => $names[$id][1], 'name' => ucfirst(strtolower($names[$id][1]))]])
                    : Http::response(['code' => 404, 'type' => 'BLZWEBAPI00000404', 'detail' => 'Not Found'], 404);
            },
        ]);
    }

    /**
     * Writes a commodities JSON file with $auctions rows spread over $items item ids, streaming
     * it to disk so the test itself doesn't hold it in memory.
     */
    protected function bigCommoditiesFile(int $auctions, int $items): string
    {
        $path = $this->tempFile(prefix: 'wfl-big-');
        $h = fopen($path, 'w');
        fwrite($h, '{"_links":{},"auctions":[');

        for ($i = 0; $i < $auctions; $i++) {
            $id = 100000 + ($i % $items);
            fwrite($h, ($i ? ',' : '').'{"id":'.(900000000 + $i).',"item":{"id":'.$id.'},"quantity":'.(1 + $i % 50).',"unit_price":'.(1000 + ($i * 7) % 5000).',"time_left":"LONG"}');
        }

        fwrite($h, ']}');
        fclose($h);

        return $path;
    }
}
