<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Services\IngestService;
use App\Services\ReconcileService;
use App\Services\UploadService;
use App\Support\TsmSpan;
use App\Support\Utc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AddonFile;
use Tests\Support\IngestsFiles;
use Tests\Support\SeedsMarketData;
use Tests\Support\TsmFile;
use Tests\TestCase;

class AddonIngestTest extends TestCase
{
    use IngestsFiles;
    use RefreshDatabase;
    use SeedsMarketData;

    private const ITEM = 238371;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_an_addon_file_alone_fills_the_ledger_tables(): void
    {
        $upload = $this->addon([
            'buy|'.self::T.'|Area52|238371|200|8000001',
            'sale|'.(self::T + 100).'|Area52|238371|50|2000000|100000',
            'expired|'.(self::T + 200).'|Area52|238371|120',
            'cancelled|'.(self::T + 300).'|Area52|238371|15',
        ], ['238371:132']);

        $this->assertSame('ok', $upload->status);
        $this->assertSame(1, $upload->counts['sales']['new']);
        $this->assertSame(0, $upload->counts['covered_by_tsm']);

        $sale = DB::table('tsm_sales')->where('user_id', $this->user->id)->first();
        $this->assertSame('addon', $sale->origin);
        $this->assertSame(38000, (int) $sale->unit_price_copper);
        $this->assertSame(Utc::sql(self::T + 100), $sale->sold_at);

        $buy = DB::table('tsm_buy_candidates')->where('user_id', $this->user->id)->first();
        $this->assertSame(8000001, (int) $buy->cost_copper, 'the exact total, not qty x floor(unit)');
        $this->assertSame(ReconcileService::PENDING, $buy->status, 'buys still wait in Reconcile');
        $this->assertSame('addon', $buy->origin);

        $this->assertSame(1, $this->rowsOf('tsm_expired', 'addon'));
        $this->assertSame(1, $this->rowsOf('tsm_cancelled', 'addon'));
        $this->assertSame(132, (int) DB::table('inventory')->where('user_id', $this->user->id)->where('item_id', self::ITEM)->value('qty'));
        $this->assertNull($this->span());
    }

    public function test_reuploading_the_same_addon_file_adds_nothing(): void
    {
        $events = ['sale|'.self::T.'|Area52|238371|50|2000000|100000', 'buy|'.self::T.'|Area52|238371|10|5000'];
        $this->addon($events);
        $again = $this->addon($events);

        $this->assertSame(0, $again->counts['sales']['new']);
        $this->assertSame(0, $again->counts['buy_candidates']['new']);
        $this->assertSame(1, $this->rowsOf('tsm_sales'));
    }

    public function test_two_identical_addon_sales_keep_distinct_rows(): void
    {
        $sale = 'sale|'.self::T.'|Area52|238371|50|2000000|100000';
        $this->addon([$sale, $sale]);

        $this->assertSame(2, $this->rowsOf('tsm_sales'));
    }

    public function test_addon_rows_inside_the_span_tsm_covers_are_not_stored(): void
    {
        $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']]);

        $upload = $this->addon([
            'sale|'.(self::T + 1000).'|Area52|238371|10|450000|22500',
            'sale|'.(self::T + 30 * self::DAY).'|Area52|238371|5|250000|12500',
        ], last: self::T + 31 * self::DAY);

        $this->assertSame(1, $upload->counts['covered_by_tsm'], 'the sale inside TSM span');
        $this->assertSame(1, $this->rowsOf('tsm_sales', 'tsm'));
        $this->assertSame(1, $this->rowsOf('tsm_sales', 'addon'), 'the sale after TSM stopped');
        $this->assertSame(Utc::sql(self::T + 30 * self::DAY), DB::table('tsm_sales')->where('origin', 'addon')->value('sold_at'));
    }

    public function test_a_tsm_upload_removes_addon_rows_it_now_covers(): void
    {
        $this->addon([
            'sale|'.(self::T + 1000).'|Area52|238371|10|450000|22500',
            'sale|'.(self::T + 30 * self::DAY).'|Area52|238371|5|250000|12500',
            'buy|'.(self::T + 1000).'|Area52|238371|10|400000',
        ]);
        $this->assertSame(2, $this->rowsOf('tsm_sales', 'addon'));

        $upload = $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']]);

        $this->assertSame(2, $upload->counts['addon_replaced'], 'the sale and the buy inside the span');
        $this->assertSame(0, $this->rowsOf('tsm_buy_candidates', 'addon'));
        $this->assertSame(1, $this->rowsOf('tsm_sales', 'tsm'));
        $this->assertSame(1, $this->rowsOf('tsm_sales', 'addon'), 'the later addon sale stays');
    }

    public function test_an_ignored_addon_buy_passes_the_ignore_to_its_tsm_twin(): void
    {
        $this->addon(['buy|'.(self::T + 1000).'|Area52|238371|10|400000', 'buy|'.(self::T + 2000).'|Area52|238371|3|90000']);
        $ignored = DB::table('tsm_buy_candidates')->where('qty', 10)->value('id');
        app(ReconcileService::class)->ignore($this->user, (int) $ignored);

        app(IngestService::class)->ingest($this->user, TsmFile::make(buys: [
            ['i:'.self::ITEM, 10, 40000, self::T + 970, 'Auction'],
            ['i:'.self::ITEM, 3, 30000, self::T + 2000, 'Auction'],
        ]), 'uploader', self::T + 20 * self::DAY);

        $this->assertSame(0, $this->rowsOf('tsm_buy_candidates', 'addon'));
        $status = DB::table('tsm_buy_candidates')->where('origin', 'tsm')->pluck('status', 'qty');
        $this->assertSame(ReconcileService::IGNORED, $status[10], 'the ignore carries over');
        $this->assertSame(ReconcileService::PENDING, $status[3], 'an undecided buy stays pending');
    }

    public function test_the_span_only_ever_widens(): void
    {
        $this->tsm([['i:'.self::ITEM, 1, 100, self::T + 5000, 'Auction']], self::T + 10 * self::DAY);
        $this->tsm([['i:'.self::ITEM, 1, 100, self::T + 6000, 'Auction']], self::T + 12 * self::DAY);
        // An older file arriving late must not shrink it.
        $this->tsm([['i:'.self::ITEM, 1, 100, self::T + 5000, 'Auction']], self::T + 9 * self::DAY);

        $this->assertSame([Utc::sql(self::T + 5000), Utc::sql(self::T + 12 * self::DAY)], $this->span());
    }

    public function test_tsm_rows_are_still_idempotent_and_marked_tsm(): void
    {
        $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']]);
        $again = $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']]);

        $this->assertSame(0, $again->counts['sales']['new']);
        $this->assertSame('tsm', DB::table('tsm_sales')->value('origin'));
    }

    public function test_the_newer_inventory_snapshot_wins_whatever_its_source(): void
    {
        $this->tsm([], self::T + 5 * self::DAY, ['i:'.self::ITEM => 10]);
        $this->addon([], ['238371:99'], self::T + 8 * self::DAY);

        $this->assertSame(99, (int) DB::table('inventory')->where('user_id', $this->user->id)->value('qty'));

        // An older addon save does not roll it back.
        $this->addon([], ['238371:1'], self::T + 6 * self::DAY);
        $this->assertSame(99, (int) DB::table('inventory')->where('user_id', $this->user->id)->value('qty'));
    }

    public function test_an_addon_snapshot_updates_only_the_items_it_saw(): void
    {
        $this->tsm([], self::T + 5 * self::DAY, ['i:'.self::ITEM => 10, 'i:777' => 4]);
        $this->addon([], ['238371:99'], self::T + 8 * self::DAY);

        $qty = fn (int $id) => (int) DB::table('inventory')->where('user_id', $this->user->id)->where('item_id', $id)->value('qty');
        $this->assertSame(99, $qty(self::ITEM));
        $this->assertSame(4, $qty(777), 'a partial addon scan does not drop what TSM counted');

        // A TSM file is a full count and replaces everything.
        $this->tsm([], self::T + 9 * self::DAY, ['i:'.self::ITEM => 1]);
        $this->assertSame(1, $qty(self::ITEM));
        $this->assertSame(0, $qty(777));
    }

    public function test_the_api_takes_an_addon_file(): void
    {
        $this->postIngest(AddonFile::make(['sale|'.self::T.'|Area52|238371|50|2000000|100000']), $this->user->issueIngestToken('test'))
            ->assertOk()
            ->assertJsonPath('counts.sales.new', 1);
    }

    public function test_the_api_refuses_an_unknown_game(): void
    {
        $this->postIngest(AddonFile::make(['sale|'.self::T.'|Area52|238371|50|2000000|100000']), $this->user->issueIngestToken('test'), ['X-Wow-Game' => 'midnight'])
            ->assertStatus(422);

        $this->assertSame(0, $this->rowsOf('tsm_sales'));
        $this->assertSame(0, Upload::ownedBy($this->user)->count());
    }

    public function test_a_tsm_file_without_a_file_time_ends_the_span_at_its_last_row(): void
    {
        $this->addon(['sale|'.(self::T + 30 * self::DAY).'|Area52|238371|5|250000|12500']);

        $upload = $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']], null);

        $this->assertSame(0, $upload->counts['addon_replaced'], '"received now" must not cover the later addon sale');
        $this->assertSame(1, $this->rowsOf('tsm_sales', 'addon'));
        $this->assertSame(Utc::sql(self::T + 1000), $this->span()[1]);
    }

    public function test_undoing_a_tsm_upload_keeps_the_span_older_tsm_rows_cover(): void
    {
        $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']], self::T + 5 * self::DAY);
        $newer = $this->tsm([
            ['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction'],
            ['i:'.self::ITEM, 2, 41000, self::T + 6 * self::DAY, 'Auction'],
        ], self::T + 7 * self::DAY);

        app(UploadService::class)->undo($this->user, $newer->id, true);

        $this->assertSame([Utc::sql(self::T + 1000), Utc::sql(self::T + 1000)], $this->span());

        // The same sale from the addon is still TSM's: not counted twice.
        $this->addon(['sale|'.(self::T + 1000).'|Area52|238371|10|450000|22500']);
        $this->assertSame(0, $this->rowsOf('tsm_sales', 'addon'));
        $this->assertSame(1, $this->rowsOf('tsm_sales', 'tsm'));
    }

    public function test_undoing_a_tsm_upload_lets_the_addon_rows_it_removed_come_back(): void
    {
        $this->addon(['sale|'.(self::T + 30 * self::DAY).'|Area52|238371|5|250000|12500']);
        $tsm = $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']], self::T + 40 * self::DAY);
        $this->assertSame(0, $this->rowsOf('tsm_sales', 'addon'));

        app(UploadService::class)->undo($this->user, $tsm->id, false);
        $this->assertNull($this->span());

        $this->addon(['sale|'.(self::T + 30 * self::DAY).'|Area52|238371|5|250000|12500']);
        $this->assertSame(1, $this->rowsOf('tsm_sales', 'addon'));
    }

    public function test_the_span_is_per_wow_account(): void
    {
        $sale = 'sale|'.(self::T + 1000).'|Area52|238371|10|450000|22500';
        $this->addon([$sale], account: 'acct-aaaaaa-addon');
        $this->addon([$sale.'0'], account: 'acct-bbbbbb-addon');

        // TSM on account A replaces A's addon rows only.
        $upload = $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']], account: 'acct-aaaaaa');

        $this->assertSame(1, $upload->counts['addon_replaced']);
        $this->assertSame(['acct-bbbbbb'], DB::table('tsm_sales')->where('origin', 'addon')->pluck('account')->all());
        $this->assertNotNull($this->span(account: 'acct-aaaaaa'));
        $this->assertNull($this->span());

        // ...and hides only A's later uploads inside it.
        $this->assertSame(1, $this->addon([$sale], account: 'acct-aaaaaa-addon')->counts['covered_by_tsm']);
        $this->assertSame(0, $this->addon(['sale|'.(self::T + 2000).'|Area52|238371|1|45000|2250'], account: 'acct-bbbbbb-addon')->counts['covered_by_tsm']);
    }

    public function test_an_unknown_account_matches_every_account(): void
    {
        $sale = 'sale|'.(self::T + 1000).'|Area52|238371|10|450000|22500';
        $this->addon([$sale], account: 'acct-aaaaaa-addon');

        // A manual TSM upload (no account) covers every account's addon rows, as before accounts.
        $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']]);
        $this->assertSame(0, $this->rowsOf('tsm_sales', 'addon'));
        $this->assertSame(1, $this->addon([$sale], account: 'acct-bbbbbb-addon')->counts['covered_by_tsm']);

        // A manual addon upload is checked against every account's span.
        $this->tsm([['i:'.self::ITEM, 1, 100, self::T + 35 * self::DAY, 'Auction']], self::T + 36 * self::DAY, account: 'acct-cccccc');
        $this->assertSame(1, $this->addon(['sale|'.(self::T + 35 * self::DAY).'|Area52|238371|1|45000|2250'])->counts['covered_by_tsm']);
    }

    public function test_undo_rebuilds_each_accounts_span(): void
    {
        $this->tsm([['i:'.self::ITEM, 10, 41000, self::T + 1000, 'Auction']], self::T + 5 * self::DAY, account: 'acct-aaaaaa');
        $b = $this->tsm([['i:'.self::ITEM, 3, 41000, self::T + 2 * self::DAY, 'Auction']], self::T + 6 * self::DAY, account: 'acct-bbbbbb');

        app(UploadService::class)->undo($this->user, $b->id, false);

        $this->assertSame([Utc::sql(self::T + 1000), Utc::sql(self::T + 1000)], $this->span(account: 'acct-aaaaaa'));
        $this->assertNull($this->span(account: 'acct-bbbbbb'));
    }

    public function test_the_account_drops_the_game_and_addon_tags(): void
    {
        $this->assertSame('acct-1a2b3c', TsmSpan::account('acct-1a2b3c'));
        $this->assertSame('acct-1a2b3c', TsmSpan::account('acct-1a2b3c-addon'));
        $this->assertSame('acct-1a2b3c', TsmSpan::account('acct-1a2b3c-forever-addon'));
        $this->assertSame('acct-1a2b3c', TsmSpan::account('acct-1a2b3c-classic'));
        $this->assertSame('', TsmSpan::account(null));
    }

    public function test_an_addon_snapshot_drops_items_it_counted_before_and_no_longer_lists(): void
    {
        $this->tsm([], self::T + 5 * self::DAY, ['i:777' => 4]);
        $this->addon([], ['238371:5', '888:2'], self::T + 8 * self::DAY);
        $this->addon([], ['238371:5'], self::T + 9 * self::DAY);

        $held = DB::table('inventory')->where('user_id', $this->user->id)->orderBy('item_id')->pluck('qty', 'item_id')->map(fn ($q) => (int) $q)->all();
        $this->assertSame([777 => 4, self::ITEM => 5], $held, '888 sold out; 777 was only ever counted by TSM');
    }

    public function test_a_waiting_sale_gets_its_id_from_a_unique_catalog_name(): void
    {
        $this->seedItem(111, 'Void-Tempered Hide', 'blizzard');
        $this->seedItem(222, 'Tiered Thing', 'blizzard');
        $this->seedItem(223, 'Tiered Thing', 'blizzard');

        $upload = app(IngestService::class)->ingest($this->user, AddonFile::make([], [], unresolved: [
            'sale|'.self::T.'|Whisperwind|Void-Tempered Hide|2|166000|8300',
            'sale|'.(self::T + 1).'|Whisperwind|void-tempered hide|1|1000|50',
            'sale|'.self::T.'|Whisperwind|Tiered Thing|1|1000|50',
            'sale|'.self::T.'|Whisperwind|Nobody Knows|1|1000|50',
        ]), 'manual');

        $this->assertSame(2, $upload->counts['sales']['new']);
        $this->assertSame(2, $upload->counts['skipped'], 'a shared name and an unknown name are not guessed');
        $this->assertSame(2, DB::table('tsm_sales')->where('user_id', $this->user->id)->where('item_id', 111)->count());

        // The same sale arriving later with its id (resolved in game) is the same row.
        $again = app(IngestService::class)->ingest($this->user, AddonFile::make([
            'sale|'.self::T.'|Whisperwind|111|2|166000|8300',
        ]), 'manual');
        $this->assertSame(0, $again->counts['sales']['new']);
    }

    public function test_a_file_with_no_valid_records_is_empty_and_garbage_fails(): void
    {
        $empty = app(IngestService::class)->ingest($this->user, "WowFlipLedgerDB = {\n\t[\"events\"] = {\n\t\t\"x\",\n\t},\n}\n", 'manual');

        $this->assertSame('ok', $empty->status);
        $this->assertSame(0, $this->rowsOf('tsm_sales'));

        $this->assertSame('failed', app(IngestService::class)->ingest($this->user, 'garbage', 'manual')->status);
    }
}
