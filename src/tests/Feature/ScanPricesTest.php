<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AddonParser;
use App\Services\DataExportService;
use App\Services\Freshness;
use App\Services\IngestService;
use App\Services\ItemDetailService;
use App\Services\PositionsService;
use App\Services\PricePruner;
use App\Services\ScanStatsService;
use App\Services\TradesService;
use App\Support\Game;
use App\Support\MarketStats;
use App\Support\Utc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AddonFile;
use Tests\Support\IngestsFiles;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

/** Forever's prices: the user's own addon scans, stored and turned into item_stats-shaped numbers. */
class ScanPricesTest extends TestCase
{
    use IngestsFiles;
    use RefreshDatabase;
    use SeedsMarketData;

    private const ITEM = 2770;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** "time|item|lowest|qty|auctions" a number of hours after T. */
    private function scan(int $hours, int $floor, int $qty = 100, int $item = self::ITEM, int $auctions = 3): string
    {
        return implode('|', [self::T + $hours * Utc::HOUR + 120, $item, $floor, $qty, $auctions]);
    }

    private function scans(string $game = Game::FOREVER): int
    {
        return DB::table('scan_prices')->where('game', $game)->count();
    }

    private function stat(string $game = Game::FOREVER, ?User $user = null): ?object
    {
        return MarketStats::forItems($user ?? $this->user, $game, [self::ITEM])[self::ITEM] ?? null;
    }

    private function ingestAs(User $user, array $prices): void
    {
        app(IngestService::class)->ingest($user, AddonFile::make(prices: $prices, interface: 16001), 'uploader', self::T + 10 * self::DAY, game: Game::FOREVER);
    }

    public function test_the_parser_reads_price_scans_and_skips_malformed_ones(): void
    {
        $data = (new AddonParser)->parse(AddonFile::make(prices: [
            $this->scan(0, 150),
            'garbage',
            self::T.'|2770|x|5|1',
            self::T.'|2770|150|5',
        ], interface: 16001));

        $this->assertSame([[
            'time' => (string) (self::T + 120), 'itemString' => 'i:2770', 'floor' => '150', 'quantity' => '100', 'auctions' => '3',
        ]], $data->rows('csvPrices'));
        $this->assertSame(Game::FOREVER, $data->game);
    }

    public function test_a_forever_upload_stores_scans_and_stats(): void
    {
        $upload = $this->addon([], prices: [$this->scan(0, 150, 400), $this->scan(1, 140, 380), $this->scan(2, 160, 300)], game: Game::FOREVER);

        $this->assertSame(['seen' => 3], $upload->counts['prices']);
        $this->assertSame(3, $this->scans());

        $stat = $this->stat();

        $this->assertSame(160, (int) $stat->floor, 'the newest scan is the floor');
        $this->assertSame(300, (int) $stat->quantity);
        $this->assertSame(150, (int) $stat->recent, 'median of the last 24 h');
        $this->assertSame(1, (int) $stat->days_of_data);
        $this->assertNull($stat->sold_per_day_est, 'scans are too sparse to estimate sales');
        $this->assertNull($this->stat(Game::RETAIL), 'retail never sees them');
    }

    public function test_derived_numbers_wait_for_min_days(): void
    {
        $prices = [];
        foreach (range(0, 12) as $day) {
            $prices[] = $this->scan($day * 24, 100 + $day);
        }
        $this->addon([], prices: $prices, game: Game::FOREVER);

        $this->assertSame(13, (int) $this->stat()->days_of_data);
        $this->assertFalse(MarketStats::ready($this->stat()));
        $this->assertNull(MarketStats::derived($this->stat(), 'typical'));

        $this->addon([], prices: [$this->scan(13 * 24, 113)], game: Game::FOREVER);

        $this->assertTrue(MarketStats::ready($this->stat()));
        $this->assertSame(107, MarketStats::derived($this->stat(), 'typical'), 'median of the daily medians');
        $this->assertSame(107, MarketStats::derived($this->stat(), 'hist60'));
    }

    public function test_one_row_per_item_per_hour_and_a_later_upload_replaces_it(): void
    {
        $first = $this->addon([], prices: [$this->scan(0, 150, auctions: 0), $this->scan(0, 140, auctions: 0)], game: Game::FOREVER);
        $this->assertSame(1, $first->counts['prices']['seen'], 'two scans in an hour are one row');

        // The addon replaced the hour's browse with a full search after the first sync.
        $this->addon([], prices: [$this->scan(0, 130, 999, auctions: 7)], game: Game::FOREVER);

        $this->assertSame(1, $this->scans());
        $row = DB::table('scan_prices')->first();
        $this->assertSame([130, 999, 7], [(int) $row->floor, (int) $row->quantity, (int) $row->num_auctions]);
        $this->assertSame(130, (int) $this->stat()->floor);
    }

    public function test_implausible_scans_are_dropped(): void
    {
        $upload = $this->addon([], prices: [
            $this->scan(0, 0),
            $this->scan(1, 150, 0),
            $this->scan(2, 150_000_000_000_000),
            (time() + 30 * Utc::DAY).'|2770|150|5|1',
            (time() + 3 * Utc::HOUR).'|2770|150|5|1',
            '1000000000|2770|150|5|1',
            $this->scan(3, 150),
        ], game: Game::FOREVER);

        $this->assertSame(1, $upload->counts['prices']['seen'], 'a clock hours ahead must not anchor the stats');
    }

    public function test_retail_stores_no_scans(): void
    {
        $upload = $this->addon([], prices: [$this->scan(0, 150)], game: Game::RETAIL);

        $this->assertArrayNotHasKey('prices', $upload->counts);
        $this->assertSame(0, DB::table('scan_prices')->count());
    }

    public function test_scans_are_kept_per_game_and_region(): void
    {
        $this->addon([], prices: [$this->scan(0, 150)], game: Game::FOREVER);
        $this->addon([], prices: [$this->scan(0, 90)], game: Game::CLASSIC);

        $this->assertSame(150, (int) $this->stat(Game::FOREVER)->floor);
        $this->assertSame(90, (int) $this->stat(Game::CLASSIC)->floor);

        $this->user->update(['region' => 'eu']);
        $this->assertNull($this->stat(Game::FOREVER), 'another region has its own prices');
    }

    public function test_one_users_scans_never_price_another_users_items(): void
    {
        $this->addon([], prices: [$this->scan(0, 150), $this->scan(1, 150)], game: Game::FOREVER);
        $attacker = User::factory()->create();
        $this->ingestAs($attacker, [$this->scan(0, 1), $this->scan(1, 1), $this->scan(2, 1)]);

        $this->assertSame(150, (int) $this->stat()->floor, 'the attacker\'s newer, lower scan is not ours');
        $this->assertSame(1, (int) $this->stat(user: $attacker)->floor);
        $this->assertSame(intdiv(self::T + Utc::HOUR + 120, Utc::HOUR) * Utc::HOUR, Freshness::for($this->user, Game::FOREVER)->pricesAt);

        $this->seedItem(self::ITEM, 'Copper Ore');
        app(TradesService::class)->add($this->user, self::ITEM, 10, 1000, self::T, null, game: Game::FOREVER);
        $this->assertSame([150, 150], array_column(app(ItemDetailService::class)->forUser($this->user, self::ITEM, Game::FOREVER)['daily'], 'median'));
    }

    public function test_scans_are_exported_and_deleted_with_the_account(): void
    {
        $this->seedItem(self::ITEM, 'Copper Ore');
        $this->addon([], prices: [$this->scan(0, 150, 40, auctions: 2)], game: Game::FOREVER);
        $other = User::factory()->create();
        $this->ingestAs($other, [$this->scan(0, 90)]);

        $zip = new \ZipArchive;
        $path = app(DataExportService::class)->build($this->user);
        $zip->open($path);
        $csv = $zip->getFromName('price_scans.csv');
        $zip->close();
        unlink($path);

        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));
        $this->assertSame('time,item,item_name,lowest_unit_price_copper,quantity,listings,region,game', $lines[0]);
        $this->assertCount(2, $lines, 'only this user\'s scan');
        $this->assertSame(['i:2770', 'Copper Ore', '150', '40', '2', 'us', 'forever'], array_slice(str_getcsv($lines[1]), 1));

        $this->user->delete();

        $this->assertSame(1, DB::table('scan_prices')->count());
        $this->assertSame(1, DB::table('scan_stats')->count());
    }

    public function test_positions_use_the_scan_floor(): void
    {
        $this->seedItem(self::ITEM, 'Copper Ore');
        app(TradesService::class)->add($this->user, self::ITEM, 10, 1000, self::T, null, game: Game::FOREVER);

        $before = app(PositionsService::class)->forUser($this->user, Game::FOREVER);
        $this->assertNull($before->summary['stock_at_floor'], 'nothing priced yet');
        $this->assertNull($before->positions[0]->floor);

        $this->addon([], prices: [$this->scan(0, 200)], game: Game::FOREVER);

        $portfolio = app(PositionsService::class)->forUser($this->user, Game::FOREVER);
        $row = $portfolio->positions[0];

        $this->assertSame(200, $row->floor);
        $this->assertNotNull($row->valueAtFloor);
        $this->assertNotNull($portfolio->summary['unrealized']);
        $this->assertFalse($row->statsReady, 'one day of data is still collecting');
        $this->assertTrue(app(PositionsService::class)->forUser($this->user)->isEmpty(), 'Retail is untouched');
    }

    public function test_the_item_page_charts_scans(): void
    {
        $this->seedItem(self::ITEM, 'Copper Ore');
        app(TradesService::class)->add($this->user, self::ITEM, 10, 1000, self::T, null, game: Game::FOREVER);
        $this->addon([], prices: [$this->scan(0, 150), $this->scan(2, 130), $this->scan(26, 170)], game: Game::FOREVER);

        $d = app(ItemDetailService::class)->forUser($this->user, self::ITEM, Game::FOREVER);

        $this->assertSame([140, 170, 170], array_column($d['daily'], 'median'), 'a median per day, then the latest scan');
        $this->assertSame([130, 170, 170], array_column($d['daily'], 'min'));
    }

    public function test_the_header_shows_the_newest_scan(): void
    {
        $this->addon([], prices: [$this->scan(5, 150)], game: Game::FOREVER);

        $forever = Freshness::for($this->user, Game::FOREVER);
        $retail = Freshness::for($this->user);

        $this->assertSame(intdiv(self::T + 5 * Utc::HOUR + 120, Utc::HOUR) * Utc::HOUR, $forever->pricesAt, 'the scan, cut to its hour');
        $this->assertTrue($forever->scans);
        $this->assertFalse($forever->pricesStale());
        $this->assertNull($retail->pricesAt, 'Retail still reads fetch runs');
    }

    public function test_pruning_drops_old_scans_only(): void
    {
        $now = time();
        $row = ['user_id' => $this->user->id, 'region' => 'us', 'game' => 'forever', 'item_id' => 1, 'floor' => 5, 'quantity' => 5, 'num_auctions' => 1];
        DB::table('scan_prices')->insert([
            $row + ['taken_at' => Utc::sql($now - 100 * Utc::DAY)],
            $row + ['taken_at' => Utc::sql($now - Utc::DAY)],
        ]);

        $this->assertSame(1, app(PricePruner::class)->prune()['scans']);
        $this->assertSame(1, DB::table('scan_prices')->count());
    }

    public function test_stats_drop_items_with_no_scan_left_in_the_window(): void
    {
        $this->addon([], prices: [$this->scan(0, 150), $this->scan(0, 90, 10, 2771)], game: Game::FOREVER);
        $this->assertSame(2, DB::table('scan_stats')->count());

        DB::table('scan_prices')->where('item_id', 2771)->delete();
        app(ScanStatsService::class)->recompute($this->user, Game::FOREVER);

        $this->assertSame([self::ITEM], DB::table('scan_stats')->pluck('item_id')->map(fn ($i) => (int) $i)->all());
    }
}
