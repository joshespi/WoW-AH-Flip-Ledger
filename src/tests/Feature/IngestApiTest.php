<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Utc;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\Fixtures;
use Tests\Support\SeedsMarketData;
use Tests\Support\TsmFile;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    use Fixtures, RefreshDatabase, SeedsMarketData;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->issueIngestToken('test');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function ingest(string $body, ?string $token = null, array $headers = []): TestResponse
    {
        // The auth manager caches the resolved user for the life of the app; tests that switch
        // tokens mid-test need a fresh guard.
        $this->app['auth']->forgetGuards();

        $server = $this->transformHeadersToServerVars($headers + [
            'Authorization' => 'Bearer '.($token ?? $this->token),
            'Accept' => 'application/json',
            'Content-Type' => 'application/octet-stream',
        ]);

        return $this->call('POST', '/api/v1/ingest', [], [], [], $server, $body);
    }

    private function rows(string $table, ?User $user = null): int
    {
        return DB::table($table)->where('user_id', ($user ?? $this->user)->id)->count();
    }

    private function fixtureFile(): string
    {
        return $this->fixture('tsm/TradeSkillMaster.lua');
    }

    public function test_uploads_name_only_unnamed_commodities_the_price_fetch_created(): void
    {
        $this->ingest($this->fixtureFile())->assertOk();
        $this->assertSame(0, DB::table('items')->count(), 'an upload never adds catalog rows for ids it only mentions');

        $this->seedItem(238371, commodity: false);
        $this->ingest($this->fixtureFile())->assertOk();
        $this->assertDatabaseHas('items', ['id' => 238371, 'name' => null]);

        DB::table('items')->where('id', 238371)->update(['is_commodity' => true]);
        $this->ingest($this->fixtureFile())->assertOk();
        $this->assertDatabaseHas('items', ['id' => 238371, 'name' => 'Arcane Wyrmfish', 'name_source' => 'tsm']);

        DB::table('items')->where('id', 238371)->update(['name' => 'Blizzard Name', 'name_source' => 'blizzard']);
        $this->ingest($this->fixtureFile())->assertOk();
        $this->assertDatabaseHas('items', ['id' => 238371, 'name' => 'Blizzard Name']);
        $this->assertSame(1, DB::table('items')->count());
    }

    public function test_stores_rows_for_the_token_owner(): void
    {
        $this->seedItem(238371);

        $response = $this->ingest($this->fixtureFile(), headers: ['X-File-Mtime' => (string) TsmFile::T0, 'X-Client-Version' => '1.2.3']);

        $response->assertOk()->assertJsonPath('status', 'ok');

        $counts = $response->json('counts');
        $this->assertGreaterThan(500, $counts['sales']['new']);
        $this->assertSame($counts['sales']['new'], $this->rows('tsm_sales'));
        $this->assertSame($counts['expired']['new'], $this->rows('tsm_expired'));
        $this->assertSame($counts['cancelled']['new'], $this->rows('tsm_cancelled'));
        $this->assertGreaterThan(0, $this->rows('tsm_buy_candidates'));
        $this->assertGreaterThan(0, $this->rows('inventory'));

        $this->assertDatabaseHas('items', ['id' => 238371, 'name' => 'Arcane Wyrmfish', 'name_source' => 'tsm']);
        $this->assertDatabaseHas('uploads', [
            'user_id' => $this->user->id, 'source' => 'uploader', 'status' => 'ok',
            'client_version' => '1.2.3', 'file_mtime' => Utc::sql(TsmFile::T0),
        ]);
    }

    public function test_a_reupload_inserts_nothing_new(): void
    {
        $counts = fn () => array_map(fn ($t) => $this->rows($t), ['tsm_sales', 'tsm_expired', 'tsm_cancelled', 'tsm_buy_candidates', 'inventory']);
        $this->ingest($this->fixtureFile())->assertOk();
        $before = $counts();

        $again = $this->ingest($this->fixtureFile());

        $again->assertOk();
        foreach (['sales', 'expired', 'cancelled', 'buy_candidates'] as $kind) {
            $this->assertSame(0, $again->json("counts.{$kind}.new"), $kind);
        }
        $this->assertSame($before, $counts());
    }

    public function test_identical_sales_both_survive_and_stay_stable_across_reuploads(): void
    {
        $sale = ['i:100', 5, 200, TsmFile::T0, 'Auction'];

        $this->ingest(TsmFile::make(sales: [$sale, $sale]))->assertOk()->assertJsonPath('counts.sales.new', 2);
        $this->assertSame(2, $this->rows('tsm_sales'));

        $this->ingest(TsmFile::make(sales: [$sale, $sale]))->assertJsonPath('counts.sales.new', 0);
        $this->assertSame(2, $this->rows('tsm_sales'));

        $this->ingest(TsmFile::make(sales: [$sale, $sale, $sale]))->assertJsonPath('counts.sales.new', 1);
        $this->assertSame(3, $this->rows('tsm_sales'));
    }

    public function test_accepts_a_gzipped_body_and_a_multipart_file(): void
    {
        $file = TsmFile::make(sales: [['i:100', 5, 200, TsmFile::T0, 'Auction']]);

        $this->ingest(gzencode($file))->assertOk()->assertJsonPath('counts.sales.new', 1);

        $this->app['auth']->forgetGuards();
        $file2 = TsmFile::make(sales: [['i:101', 5, 200, TsmFile::T0, 'Auction']]);
        $upload = UploadedFile::fake()->createWithContent('TradeSkillMaster.lua', $file2);

        $this->withToken($this->token)->postJson('/api/v1/ingest', ['file' => $upload])
            ->assertOk()->assertJsonPath('counts.sales.new', 1);
    }

    public function test_a_malformed_file_leaves_existing_data_untouched(): void
    {
        $this->ingest($this->fixtureFile())->assertOk();
        $sales = $this->rows('tsm_sales');
        $uploads = $this->rows('uploads');

        $this->ingest("print('not tsm')\n")
            ->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertSame($sales, $this->rows('tsm_sales'));
        $this->assertSame($uploads + 1, $this->rows('uploads'));
        $this->assertDatabaseHas('uploads', ['user_id' => $this->user->id, 'status' => 'failed']);
    }

    public function test_a_gzip_bomb_is_refused_without_expanding_it(): void
    {
        $bomb = gzencode(str_repeat("\0", 60 * 1024 * 1024), 9);
        $this->assertLessThan(200_000, strlen($bomb));

        $this->ingest($bomb)->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertSame(0, $this->rows('tsm_sales'));
    }

    public function test_corrupt_gzip_and_empty_bodies_are_refused(): void
    {
        $this->ingest("\x1f\x8bthis is not gzip")->assertStatus(422);
        $this->ingest('')->assertStatus(422);
    }

    public function test_requires_a_token_with_the_ingest_ability(): void
    {
        $this->ingest('x', token: 'wfl_nonsense')->assertUnauthorized();

        $other = $this->user->createToken('other', ['something-else'])->plainTextToken;
        $this->ingest('x', token: $other)->assertForbidden();
    }

    public function test_unverified_accounts_cannot_ingest(): void
    {
        $unverified = User::factory()->unverified()->create();
        $token = $unverified->issueIngestToken('t');

        $this->ingest(TsmFile::make(), token: $token)->assertForbidden();
        $this->assertSame(0, $this->rows('uploads', $unverified));
    }

    public function test_another_users_token_only_touches_that_users_data(): void
    {
        $counts = fn () => array_map(fn ($t) => $this->rows($t), ['tsm_sales', 'inventory', 'uploads']);
        $this->ingest($this->fixtureFile())->assertOk();
        $mine = $counts();

        $bob = User::factory()->create();
        $bobToken = $bob->issueIngestToken('bob');

        $this->ingest(TsmFile::make(sales: [['i:100', 1, 100, TsmFile::T0, 'Auction']]), token: $bobToken)->assertOk();

        $this->assertSame($mine, $counts());
        $this->assertSame(1, $this->rows('tsm_sales', $bob));
        $this->assertSame(1, $this->rows('uploads', $bob));

        // Same sale rows from two accounts don't collide on the dedupe key.
        $this->ingest($this->fixtureFile(), token: $bobToken)->assertOk();
        $this->assertSame($mine[0] + 1, $this->rows('tsm_sales', $bob));
        $this->assertSame($mine[0], $this->rows('tsm_sales'));
    }

    public function test_an_older_file_does_not_overwrite_a_newer_inventory_snapshot(): void
    {
        $new = TsmFile::make(inventory: ['i:100' => 50]);
        $old = TsmFile::make(inventory: ['i:100' => 7, 'i:101' => 3]);

        $this->ingest($new, headers: ['X-File-Mtime' => (string) (TsmFile::T0 + 1000)])->assertJsonPath('counts.inventory.applied', true);
        $this->ingest($old, headers: ['X-File-Mtime' => (string) TsmFile::T0])->assertJsonPath('counts.inventory.applied', false);

        $this->assertSame([100 => 50], DB::table('inventory')->where('user_id', $this->user->id)->pluck('qty', 'item_id')->all());

        $this->ingest($old, headers: ['X-File-Mtime' => (string) (TsmFile::T0 + 2000)])->assertJsonPath('counts.inventory.applied', true);
        $this->assertSame([100 => 7, 101 => 3], DB::table('inventory')->where('user_id', $this->user->id)->orderBy('item_id')->pluck('qty', 'item_id')->all());
    }

    public function test_a_file_without_inventory_does_not_wipe_the_snapshot(): void
    {
        $this->ingest(TsmFile::make(inventory: ['i:100' => 50]))->assertOk();
        $this->ingest(TsmFile::make(sales: [['i:100', 1, 100, TsmFile::T0, 'Auction']]))->assertOk();

        $this->assertSame(1, $this->rows('inventory'));
    }

    public function test_variant_inventory_keys_sum_into_one_item(): void
    {
        $this->ingest(TsmFile::make(inventory: ['i:100' => 4, 'i:100::1:6710' => 6]))->assertOk();

        $this->assertSame(10, (int) DB::table('inventory')->where('user_id', $this->user->id)->where('item_id', 100)->value('qty'));
    }

    public function test_implausible_file_mtimes_fall_back_to_receive_time(): void
    {
        foreach (['5', (string) (time() + 10 * 86400), 'abc'] as $bad) {
            $this->ingest(TsmFile::make(), headers: ['X-File-Mtime' => $bad])->assertOk();
        }

        $this->assertSame(3, DB::table('uploads')->where('user_id', $this->user->id)->whereNull('file_mtime')->count());
    }

    public function test_item_names_only_fill_gaps_for_items_the_upload_references(): void
    {
        $this->seedItem(100, 'Blizzard Name', 'blizzard');
        $this->seedItem(101);

        $file = TsmFile::make(
            sales: [['i:100', 1, 100, TsmFile::T0, 'Auction'], ['i:101', 1, 100, TsmFile::T0, 'Auction']],
            names: ['i:100' => 'Spoofed Name', 'i:101' => "  Good\x01 Name\n", 'i:555' => 'Unreferenced'],
        );

        $this->ingest($file)->assertOk()->assertJsonPath('counts.names_added', 1);

        $this->assertSame('Blizzard Name', DB::table('items')->where('id', 100)->value('name'));
        $this->assertSame('Good Name', DB::table('items')->where('id', 101)->value('name'));
        $this->assertDatabaseMissing('items', ['id' => 555]);
    }

    public function test_only_auction_buys_become_candidates_and_pets_and_junk_rows_are_skipped(): void
    {
        $file = TsmFile::make(
            sales: [
                ['i:100', 1, 100, TsmFile::T0, 'Auction'],
                ['p:2843:1:3', 1, 100, TsmFile::T0, 'Auction'],
                ['i:100', 0, 100, TsmFile::T0, 'Auction'],
                ['i:100', 1, 100, 5, 'Auction'],
                ['i:100', 1, 100, TsmFile::T0, 'Bad Source!'],
            ],
            buys: [
                ['i:100', 10, 50, TsmFile::T0, 'Auction'],
                ['i:100', 10, 50, TsmFile::T0, 'Vendor'],
            ],
        );

        $response = $this->ingest($file)->assertOk();

        $this->assertSame(1, $this->rows('tsm_sales'));
        $this->assertSame(1, $this->rows('tsm_buy_candidates'));
        $this->assertSame(500, (int) DB::table('tsm_buy_candidates')->where('user_id', $this->user->id)->value('cost_copper'));
        $this->assertSame(4, $response->json('counts.skipped'));
    }

    public function test_outdated_uploaders_are_told_to_update(): void
    {
        config(['ledger.min_client_version' => '1.0.0']);

        $this->ingest(TsmFile::make(), headers: ['X-Client-Version' => '0.9.0'])
            ->assertStatus(426)->assertJsonPath('min_client_version', '1.0.0');
        $this->assertSame(0, $this->rows('uploads'));

        $this->ingest(TsmFile::make(), headers: ['X-Client-Version' => '1.0.0'])->assertOk();
    }

    public function test_ingest_is_throttled_per_token(): void
    {
        config(['ledger.ingest.per_token_per_hour' => 2]);

        $this->ingest(TsmFile::make())->assertOk();
        $this->ingest(TsmFile::make())->assertOk();
        $this->ingest(TsmFile::make())->assertStatus(429);

        // A different token has its own budget.
        $other = $this->user->issueIngestToken('second');
        $this->ingest(TsmFile::make(), token: $other)->assertOk();
    }

    public function test_bad_token_floods_are_limited_per_ip(): void
    {
        config(['ledger.ingest.per_ip_per_minute' => 3]);

        foreach (range(1, 3) as $_) {
            $this->ingest('x', token: 'wfl_bad')->assertUnauthorized();
        }

        $this->ingest('x', token: 'wfl_bad')->assertStatus(429);
    }

    public function test_token_last_used_is_recorded(): void
    {
        $this->assertNull($this->user->tokens()->first()->last_used_at);

        $this->ingest(TsmFile::make())->assertOk();

        $this->assertNotNull($this->user->tokens()->first()->fresh()->last_used_at);
    }

    public function test_a_failed_save_is_a_retryable_503_not_a_rejection(): void
    {
        Exceptions::fake();
        Schema::drop('tsm_sales');

        $this->ingest(TsmFile::make(sales: [['i:100', 1, 100, TsmFile::T0, 'Auction']]))
            ->assertStatus(503)->assertHeader('Retry-After', '60')->assertJsonPath('status', 'failed');
        Exceptions::assertReported(QueryException::class);
    }
}
