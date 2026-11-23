<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Services\IngestService;
use App\Services\ItemDetailService;
use App\Services\PositionsService;
use App\Services\ReconcileService;
use App\Services\TradesService;
use App\Support\Game;
use App\Support\ItemName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IngestsFiles;
use Tests\Support\SeedsMarketData;
use Tests\Support\TsmFile;
use Tests\TestCase;

class GameIngestTest extends TestCase
{
    use IngestsFiles;
    use RefreshDatabase;
    use SeedsMarketData;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_the_same_rows_in_each_game_are_kept_apart(): void
    {
        $events = ['sale|'.(self::T + 100).'|Area52|238371|50|2000000|100000'];

        $this->addon($events, game: Game::RETAIL);
        $this->addon($events, game: Game::FOREVER);

        $this->assertSame(1, $this->rowsOf('tsm_sales', game: Game::RETAIL));
        $this->assertSame(1, $this->rowsOf('tsm_sales', game: Game::FOREVER));
    }

    public function test_a_reupload_in_one_game_adds_nothing(): void
    {
        $events = ['sale|'.(self::T + 100).'|Area52|238371|50|2000000|100000'];

        $this->addon($events, game: Game::FOREVER);
        $second = $this->addon($events, game: Game::FOREVER);

        $this->assertSame(0, $second->counts['sales']['new']);
        $this->assertSame(1, $this->rowsOf('tsm_sales', game: Game::FOREVER));
        $this->assertSame(0, $this->rowsOf('tsm_sales', game: Game::RETAIL));
    }

    public function test_forever_item_names_come_from_the_users_own_addon(): void
    {
        $this->addon(['buy|'.(self::T + 100).'|Area52|238371|5|5000'], game: Game::FOREVER);

        $this->assertSame('Wyrmfish', DB::table('user_item_names')->where('user_id', $this->user->id)->where('game', Game::FOREVER)->value('name'));
        $this->assertNull(DB::table('items')->where('id', 238371)->value('name'));

        $pending = app(ReconcileService::class)->pending($this->user, Game::FOREVER);
        $this->assertSame('Wyrmfish', $pending[0]['name']);

        app(TradesService::class)->add($this->user, 238371, 1, 100, self::T, null, game: Game::FOREVER);
        $this->assertSame('Wyrmfish', app(PositionsService::class)->forUser($this->user, Game::FOREVER)->positions[0]->name);
        $this->assertSame('Wyrmfish', app(ItemDetailService::class)->forUser($this->user, 238371, Game::FOREVER)['name']);
    }

    public function test_forever_names_never_borrow_the_retail_catalog(): void
    {
        $this->seedItem(238371, 'Arcane Wyrmfish');
        app(TradesService::class)->add($this->user, 238371, 1, 100, self::T, null, game: Game::FOREVER);

        $this->assertSame('Item #238371', app(PositionsService::class)->forUser($this->user, Game::FOREVER)->positions[0]->name);
        $this->assertSame([238371 => 'Arcane Wyrmfish'], ItemName::forUser($this->user, Game::RETAIL, [238371]));
        $this->assertSame([], ItemName::forUser($this->user, Game::FOREVER, [238371]));
    }

    public function test_an_addon_upload_can_name_an_older_trade_without_sending_its_rows_again(): void
    {
        app(TradesService::class)->add($this->user, 238371, 1, 100, self::T, null, game: Game::FOREVER);

        $this->addon([], game: Game::FOREVER);

        $this->assertSame('Wyrmfish', app(PositionsService::class)->forUser($this->user, Game::FOREVER)->positions[0]->name);
    }

    public function test_a_tsm_upload_only_names_items_recorded_in_that_upload(): void
    {
        $file = TsmFile::make(
            sales: [['i:2835', 5, 1000, self::T, 'Auction']],
            names: ['i:2835' => 'Rough Stone', 'i:2770' => 'Copper Ore'],
        );

        $upload = app(IngestService::class)->ingest($this->user, $file, Upload::SOURCE_MANUAL, game: Game::CLASSIC);

        $this->assertSame(1, $upload->counts['names_added']);
        $this->assertSame([2835 => 'Rough Stone'], ItemName::forUser($this->user, Game::CLASSIC, [2835, 2770]));
    }

    public function test_the_upload_records_its_game(): void
    {
        $this->assertSame(Game::FOREVER, $this->addon([], game: Game::FOREVER)->game);
        $this->assertSame(Game::RETAIL, $this->addon([], game: Game::RETAIL)->game);
    }

    public function test_inventory_snapshots_are_per_game(): void
    {
        $this->addon([], ['238371:10'], game: Game::RETAIL);
        $this->addon([], ['238371:99'], game: Game::FOREVER);

        $this->assertSame(10, (int) DB::table('inventory')->where('game', Game::RETAIL)->value('qty'));
        $this->assertSame(99, (int) DB::table('inventory')->where('game', Game::FOREVER)->value('qty'));
    }

    public function test_a_tsm_span_in_one_game_does_not_cover_the_other(): void
    {
        // Forever TSM upload sets only the Forever span.
        $this->tsm([['i:238371', 5, 1000, self::T + 5 * self::DAY, 'Auction']], game: Game::FOREVER);

        $this->assertNotNull($this->span(Game::FOREVER));
        $this->assertNull($this->span());

        // A retail addon sale inside that stretch is still stored.
        $this->addon(['sale|'.(self::T + 5 * self::DAY).'|Area52|238371|5|2000|100'], game: Game::RETAIL);
        $this->assertSame(1, $this->rowsOf('tsm_sales', game: Game::RETAIL));
    }

    public function test_positions_follow_the_game(): void
    {
        $service = app(TradesService::class);
        $service->add($this->user, 238371, 10, 1000, self::T, null, game: Game::FOREVER);

        $positions = app(PositionsService::class);

        $this->assertTrue($positions->forUser($this->user)->isEmpty());
        $this->assertSame(10, $positions->forUser($this->user, Game::FOREVER)->positions[0]->held);
        $this->assertSame(0, $positions->held($this->user, 238371));
        $this->assertSame(10, $positions->held($this->user, 238371, Game::FOREVER));
    }

    public function test_forever_never_borrows_retail_market_numbers(): void
    {
        $this->seedItem(238371, 'Test');
        app(TradesService::class)->add($this->user, 238371, 10, 1000, self::T, null, game: Game::FOREVER);

        $row = app(PositionsService::class)->forUser($this->user, Game::FOREVER)->positions[0];

        $this->assertSame(0, $row->floor ?? 0);
        $this->assertFalse($row->statsReady);
    }
}
