<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\LoadsLedgerFixture;
use Tests\TestCase;

class AddonFeedApiTest extends TestCase
{
    use LoadsLedgerFixture;
    use RefreshDatabase;

    private function feed(?string $token, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/addon-feed', $headers + ($token === null ? [] : ['Authorization' => 'Bearer '.$token]));
    }

    private function token(User $user): string
    {
        return $user->issueIngestToken('pc');
    }

    public function test_it_returns_the_owners_open_positions_with_exact_and_derived_fields(): void
    {
        $user = $this->fixtureUser(prices: true);
        $this->seedFetchRun('ok', time() - 300, time() - 600);

        $r = $this->feed($this->token($user))->assertOk()
            ->assertJsonPath('schema', 1)
            ->assertJsonPath('region', 'us')
            ->assertJsonPath('prices_stale', false)
            ->assertJsonStructure(['generated_at', 'prices_at', 'summary' => ['realized', 'unrealized', 'stock_at_floor'], 'truncated', 'positions', 'watchlist', 'alerts']);

        $this->assertNotEmpty($r->json('positions'));

        foreach ($r->json('positions') as $p) {
            $this->assertGreaterThan(0, $p['held']);
        }
    }

    public function test_derived_fields_are_null_while_collecting_data(): void
    {
        $user = $this->fixtureUser(prices: true);

        $r = $this->feed($this->token($user))->assertOk();

        // Swarmer and Glimmerline have 5 and 3 days of history: floors are real, derived numbers are not.
        $collecting = array_values(array_filter($r->json('positions'), fn (array $p) => ! $p['stats_ready']));
        $this->assertNotEmpty($collecting);

        foreach ($collecting as $p) {
            $this->assertNull($p['recent']);
            $this->assertNull($p['typical']);
            $this->assertNull($p['market']);
        }

        $this->assertNotEmpty(array_filter($collecting, fn (array $p) => $p['floor'] !== null));

        $ready = array_filter($r->json('positions'), fn (array $p) => $p['stats_ready']);
        $this->assertNotEmpty($ready);
    }

    public function test_it_never_includes_another_users_rows(): void
    {
        $alice = $this->fixtureUser(prices: true);
        $bob = User::factory()->create();
        WatchlistItem::create(['user_id' => $alice->id, 'item_id' => self::WYRMFISH, 'target_buy_copper' => 500_000, 'notes' => 'secret note']);

        $r = $this->feed($this->token($bob))->assertOk();

        $this->assertSame([], $r->json('positions'));
        $this->assertSame([], $r->json('watchlist'));
        $this->assertSame([], $r->json('alerts'));
        $this->assertStringNotContainsString('secret note', $r->getContent());
    }

    public function test_the_watchlist_carries_targets_and_flags_but_no_notes(): void
    {
        $user = $this->fixtureUser(prices: true);
        WatchlistItem::create(['user_id' => $user->id, 'item_id' => self::WYRMFISH, 'target_buy_copper' => 450_000, 'target_sell_copper' => 500_000, 'notes' => 'my private note']);

        $r = $this->feed($this->token($user))->assertOk();
        $w = collect($r->json('watchlist'))->firstWhere('item_id', self::WYRMFISH);

        $this->assertSame(450_000, $w['buy']);
        $this->assertSame(500_000, $w['sell']);
        $this->assertTrue($w['at_buy']);
        $this->assertFalse($w['at_sell']);
        $this->assertArrayNotHasKey('notes', $w);
        $this->assertStringNotContainsString('my private note', $r->getContent());
    }

    public function test_only_alerts_that_are_on_are_listed_and_respect_the_users_switches(): void
    {
        $user = $this->fixtureUser(prices: true, attributes: ['alerts_enabled' => true, 'alert_buy_target' => true, 'alert_sell_target' => true]);
        WatchlistItem::create(['user_id' => $user->id, 'item_id' => self::WYRMFISH, 'target_buy_copper' => 450_000]);
        WatchlistItem::create(['user_id' => $user->id, 'item_id' => self::PORK, 'target_buy_copper' => 1_000]);

        $alerts = $this->feed($this->token($user))->assertOk()->json('alerts');

        $this->assertSame([self::WYRMFISH], array_column($alerts, 'item_id'));
        $this->assertSame('buy_target', $alerts[0]['kind']);

        $user->update(['alert_buy_target' => false]);

        $this->assertSame([], $this->feed($this->token($user))->assertOk()->json('alerts'));
    }

    public function test_the_feed_is_capped_and_says_so(): void
    {
        config(['ledger.addon.max_positions' => 1]);
        $user = $this->fixtureUser(prices: true);

        $r = $this->feed($this->token($user))->assertOk();

        $this->assertCount(1, $r->json('positions'));
        $this->assertTrue($r->json('truncated'));
    }

    public function test_it_needs_a_token_with_the_ingest_ability(): void
    {
        $user = User::factory()->create();

        $this->feed(null)->assertUnauthorized();
        $this->feed('wfl_nope')->assertUnauthorized();
        $this->feed($user->createToken('other', ['something'])->plainTextToken)->assertForbidden();
    }

    public function test_old_uploaders_get_426(): void
    {
        config(['ledger.min_client_version' => '1.0.0']);
        $token = $this->token(User::factory()->create());

        $this->feed($token, ['X-Client-Version' => '0.9.0'])->assertStatus(426);
        $this->feed($token, ['X-Client-Version' => '1.0.0'])->assertOk();
    }

    public function test_it_has_its_own_per_token_budget_apart_from_ingest(): void
    {
        config(['ledger.addon.per_token_per_hour' => 2, 'ledger.ingest.per_token_per_hour' => 1]);
        $token = $this->token(User::factory()->create());

        $this->feed($token)->assertOk();
        $this->feed($token)->assertOk();
        $this->feed($token)->assertStatus(429);
    }
}
