<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\User;
use App\Services\SiteStatsService;
use App\Support\Utc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LoadsLedgerFixture;
use Tests\TestCase;

class StatsPageTest extends TestCase
{
    use LoadsLedgerFixture;
    use RefreshDatabase;

    public function test_public_page_links_to_the_privacy_wording(): void
    {
        $this->get('/stats')->assertOk()
            ->assertSee('Anonymous totals across all players. No names, items or gold amounts.')
            ->assertSee(route('privacy').'#stats', false);
        $this->get('/privacy')->assertSee('id="stats"', false)->assertSee('id="scoreboard"', false)->assertSee('nothing on it identifies you');
    }

    public function test_counts_verified_non_demo_users_only(): void
    {
        $now = time();
        $a = User::factory()->create();
        $b = User::factory()->create();
        User::factory()->unverified()->create();
        $demo = User::factory()->create(['is_demo' => true]);
        $today = Utc::day($now);

        DB::table('user_activity_days')->insert([
            ['user_id' => $a->id, 'day' => $today],
            ['user_id' => $b->id, 'day' => Utc::day($now - 3 * 86400)],
            ['user_id' => $b->id, 'day' => Utc::day($now - 20 * 86400)],
            ['user_id' => $demo->id, 'day' => $today],
        ]);
        Trade::create(['user_id' => $a->id, 'item_id' => 1, 'traded_at' => Utc::sql($now), 'qty' => 1, 'cost_copper' => 5, 'side' => 'buy']);
        Trade::create(['user_id' => $demo->id, 'item_id' => 1, 'traded_at' => Utc::sql($now), 'qty' => 1, 'cost_copper' => 5, 'side' => 'buy']);

        $s = app(SiteStatsService::class)->build($now);

        $this->assertSame(2, $s['accounts']);
        $this->assertSame(1, $s['active_today']);
        $this->assertSame(2, $s['active_7d']);
        $this->assertSame(2, $s['active_30d']);
        $this->assertCount(30, $s['days']);
        $this->assertSame($today, $s['days'][29]['day']);
        $this->assertSame(1, $s['days'][29]['active']);
        $this->assertSame(1, $s['days'][29]['buys']);
        $this->assertSame(1, $s['days'][26]['active']);
        $this->assertSame(Utc::day($now - 20 * 86400), $s['tracked_since'], 'tracking began inside the window');

        // Once the window is full, the pruned table's oldest day means nothing.
        DB::table('user_activity_days')->insert(['user_id' => $b->id, 'day' => Utc::day($now - 40 * 86400)]);
        $this->assertNull(app(SiteStatsService::class)->build($now)['tracked_since']);
    }

    public function test_output_is_counts_and_dates_with_no_gold_or_names(): void
    {
        $this->fixtureUser(attributes: ['name' => 'Zed Secretname', 'email' => 'zed@example.com']);

        $s = app(SiteStatsService::class)->build();

        $this->assertSame(['accounts', 'active_today', 'active_7d', 'active_30d', 'tracked_since', 'days'], array_keys($s));
        $this->assertSame(['day', 'active', 'sales', 'buys', 'uploads'], array_keys($s['days'][0]));

        array_walk_recursive($s, function ($v, $k) {
            $this->assertTrue(is_int($v) || $v === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1, "stats value for {$k} is not a count or date");
            $this->assertStringNotContainsString('copper', (string) $k);
        });

        $html = $this->get('/stats')->assertOk()->getContent();
        $this->assertStringNotContainsString('Secretname', $html);
        $this->assertStringNotContainsString('zed@example.com', $html);
        $this->assertStringNotContainsString('Wyrmfish', $html);
        $this->assertSame(0, preg_match('/\d[gsc]<\/span>/', $html), 'money markup on /stats');
    }
}
