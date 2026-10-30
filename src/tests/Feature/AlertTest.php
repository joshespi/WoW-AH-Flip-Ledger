<?php

namespace Tests\Feature;

use App\Mail\PriceAlertsMail;
use App\Models\FetchRun;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\AlertService;
use App\Services\TradesService;
use App\Services\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;

    private int $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = time();
        Mail::fake();
        $this->seedItem(100, 'Practically Pork');
        $this->seedItem(200, 'Arcane Wyrmfish');
        $this->seedItem(300, "Sin'dorei Swarmer");
    }

    /** @param array<string, mixed> $attrs */
    private function user(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['alerts_enabled' => true, 'alert_buy_target' => true, 'alert_sell_target' => true, 'alert_profit' => false]);
    }

    private function watch(User $user, int $item, string $buy = '', string $sell = ''): void
    {
        $w = app(WatchlistService::class)->add($user, $item);
        app(WatchlistService::class)->update($user, $w->id, $buy, $sell, null, null);
    }

    private function floor(int $item, int $floor): void
    {
        $this->seedStats([$item => ['floor' => $floor]]);
    }

    private function alert(User $user, int $offset = 0): int
    {
        return app(AlertService::class)->run($user->fresh(), $this->t + $offset);
    }

    private function sentCount(): int
    {
        return Mail::sent(PriceAlertsMail::class)->count();
    }

    public function test_buy_target_fires_once_on_the_change_to_on(): void
    {
        $user = $this->user();
        $this->watch($user, 100, '10s');   // buy at or under 1000 copper
        $this->floor(100, 1500);

        $this->assertSame(0, $this->alert($user));

        $this->floor(100, 900);
        $this->assertSame(1, $this->alert($user, 3600));
        $this->assertSame(0, $this->alert($user, 7200), 'still on, no repeat');
        $this->assertSame(0, $this->alert($user, 10800));

        Mail::assertSent(PriceAlertsMail::class, 1);
        Mail::assertSent(PriceAlertsMail::class, fn ($m) => $m->hasTo($user->email) && $m->alerts[0]['item_id'] === 100 && $m->alerts[0]['kind'] === 'buy_target');
    }

    public function test_cooldown_blocks_a_flap_but_not_a_later_return(): void
    {
        $user = $this->user();
        $this->watch($user, 100, '10s');
        $this->floor(100, 900);
        $this->assertSame(1, $this->alert($user));

        $this->floor(100, 1500);
        $this->alert($user, 3600);                         // off
        $this->floor(100, 800);
        $this->assertSame(0, $this->alert($user, 7200), 'back on inside the cooldown: recorded, not mailed');
        $this->assertSame(1, (int) DB::table('alert_states')->where('user_id', $user->id)->value('is_on'));

        $this->floor(100, 1500);
        $this->alert($user, 10800);
        $this->floor(100, 700);
        $this->assertSame(1, $this->alert($user, 25 * 3600), 'a new transition after the cooldown fires');
        $this->assertSame(2, $this->sentCount());
    }

    public function test_sell_target_and_per_rule_opt_in(): void
    {
        $user = $this->user(['alert_buy_target' => false]);
        $this->watch($user, 100, '10s', '50s');
        $this->floor(100, 800);            // would hit the buy target, but that rule is off
        $this->assertSame(0, $this->alert($user));

        $this->floor(100, 6000);           // at or over 5000
        $this->assertSame(1, $this->alert($user, 3600));
        Mail::assertSent(PriceAlertsMail::class, fn ($m) => $m->alerts[0]['kind'] === 'sell_target');
    }

    public function test_profit_rule_fires_when_a_held_position_clears_break_even(): void
    {
        $user = $this->user(['alert_buy_target' => false, 'alert_sell_target' => false, 'alert_profit' => true]);
        app(TradesService::class)->add($user, 100, 10, 100_000, $this->t - 86400, null);   // 10,000 copper each

        $this->floor(100, 5000);
        $this->assertSame(0, $this->alert($user), 'under water');

        $this->floor(100, 20_000);
        $this->assertSame(1, $this->alert($user, 3600));
        $this->assertSame(0, $this->alert($user, 7200));

        Mail::assertSent(PriceAlertsMail::class, fn ($m) => $m->alerts[0]['kind'] === 'profit' && $m->alerts[0]['floor'] === 20_000);
    }

    public function test_one_digest_per_run_with_everything_that_fired(): void
    {
        $user = $this->user();
        $this->watch($user, 100, '10s');
        $this->watch($user, 200, '', '20s');
        $this->floor(100, 500);
        $this->floor(200, 5000);

        $this->assertSame(2, $this->alert($user));

        Mail::assertSent(PriceAlertsMail::class, 1);
        Mail::assertSent(PriceAlertsMail::class, fn ($m) => count($m->alerts) === 2 && str_contains($m->envelope()->subject, '2 price alerts'));
    }

    public function test_overflow_carries_to_the_next_run(): void
    {
        config(['ledger.alerts.max_items_per_email' => 2]);
        $user = $this->user();

        foreach ([100, 200, 300] as $id) {
            $this->watch($user, $id, '10s');
            $this->floor($id, 500);
        }

        $this->assertSame(2, $this->alert($user));
        $this->assertSame(1, $this->alert($user, 3600));
        $this->assertSame(0, $this->alert($user, 7200));
        $this->assertSame(3, array_sum(Mail::sent(PriceAlertsMail::class)->map(fn ($m) => count($m->alerts))->all()));
    }

    public function test_states_for_removed_targets_are_dropped(): void
    {
        $user = $this->user();
        $this->watch($user, 100, '10s');
        $this->floor(100, 500);
        $this->alert($user);
        $this->assertSame(1, DB::table('alert_states')->count());

        WatchlistItem::where('user_id', $user->id)->delete();
        $this->alert($user, 3600);

        $this->assertSame(0, DB::table('alert_states')->count());
    }

    public function test_a_failed_send_saves_nothing_so_the_next_run_retries(): void
    {
        $user = $this->user();
        $this->watch($user, 100, '10s');
        $this->floor(100, 500);

        $fake = Mail::getFacadeRoot();
        Mail::swap(new class
        {
            public function to(): never
            {
                throw new \RuntimeException('smtp down');
            }
        });

        try {
            $this->alert($user);
            $this->fail('expected the mail failure to surface');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('alert_states')->count());
        }

        Mail::swap($fake);

        $this->assertSame(1, $this->alert($user, 60));
    }

    public function test_command_needs_a_new_good_refresh_and_honours_opt_in(): void
    {
        $on = $this->user();
        $off = $this->user(['alerts_enabled' => false]);
        $unverified = $this->user(['email_verified_at' => null]);

        foreach ([$on, $off, $unverified] as $u) {
            $this->watch($u, 100, '10s');
        }

        $this->floor(100, 500);

        $this->artisan('alerts:send')->assertSuccessful();
        $this->assertSame(0, $this->sentCount(), 'no refresh has happened yet');

        $this->seedFetchRun('unchanged', time() - 60, time() - 120);
        $this->seedFetchRun('failed', time() - 30);
        $this->artisan('alerts:send')->assertSuccessful();
        $this->assertSame(0, $this->sentCount(), 'only a run with new prices counts');

        $this->seedFetchRun('ok', time() - 20, time() - 100);
        $this->artisan('alerts:send')->assertSuccessful();
        Mail::assertSent(PriceAlertsMail::class, 1);
        Mail::assertSent(PriceAlertsMail::class, fn ($m) => $m->hasTo($on->email));

        $this->artisan('alerts:send')->expectsOutputToContain('no new prices')->assertSuccessful();
        $this->assertSame(1, $this->sentCount());

        $this->seedFetchRun('ok', time() - 10, time() - 50);
        $this->artisan('alerts:send')->assertSuccessful();
        $this->assertSame(1, $this->sentCount(), 'still on: no repeat on the next refresh');
    }

    public function test_command_skips_stale_prices_unless_forced_and_skips_the_demo(): void
    {
        $user = $this->user();
        $this->watch($user, 100, '10s');
        $this->floor(100, 500);
        $this->artisan('demo:seed');
        DB::table('users')->where('is_demo', true)->update(['alerts_enabled' => true]);

        $this->seedFetchRun('ok', time() - 5 * 3600, time() - 5 * 3600);
        $this->artisan('alerts:send')->expectsOutputToContain('stale')->assertSuccessful();
        $this->assertSame(0, $this->sentCount());

        $this->artisan('alerts:send --force')->assertSuccessful();
        Mail::assertSent(PriceAlertsMail::class, 1);
        Mail::assertNotSent(PriceAlertsMail::class, fn ($m) => $m->hasTo('demo@demo.invalid'));
    }

    public function test_the_job_is_scheduled_after_the_fetch(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('alerts:send')->assertSuccessful();
    }

    public function test_mail_has_links_and_one_click_unsubscribe(): void
    {
        $user = $this->user(['name' => 'Al']);
        $alerts = [['kind' => 'buy_target', 'item_id' => 100, 'name' => 'Practically Pork', 'on' => true, 'floor' => 1234, 'target' => 2000]];
        $mail = new PriceAlertsMail($user, $alerts);

        $html = $mail->render();
        $this->assertStringContainsString('Practically Pork', $html);
        $this->assertStringContainsString(route('items.show', 100), $html);
        $this->assertStringContainsString(htmlspecialchars($mail->unsubscribeUrl()), $html);
        $this->assertStringContainsString(route('settings.edit'), $html);

        $headers = $mail->headers()->text;
        $this->assertSame('<'.$mail->unsubscribeUrl().'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertTrue(URL::hasValidSignature(Request::create($mail->unsubscribeUrl())));
    }

    public function test_unsubscribe_confirms_on_get_and_acts_on_post_without_a_session(): void
    {
        $user = $this->user();
        $url = URL::signedRoute('alerts.unsubscribe', ['user' => $user->id]);

        $this->get($url)->assertOk()->assertSee('Turn off all price alert emails');
        $this->assertTrue($user->fresh()->alerts_enabled, 'a GET changes nothing');

        $this->post($url)->assertOk()->assertSee('Price alert emails are off');
        $this->assertFalse($user->fresh()->alerts_enabled);

        $this->get($url)->assertSee('are off');
    }

    public function test_unsubscribe_refuses_unsigned_and_tampered_links(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $url = URL::signedRoute('alerts.unsubscribe', ['user' => $alice->id]);

        $this->post(route('alerts.unsubscribe', $alice->id))->assertForbidden();
        $this->post(str_replace("/{$alice->id}?", "/{$bob->id}?", $url))->assertForbidden();
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        $this->assertTrue($alice->fresh()->alerts_enabled && $bob->fresh()->alerts_enabled);
    }

    public function test_settings_saves_the_rules_and_alerts_default_to_off(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->fresh()->alerts_enabled);

        $this->actingAs($user)->patch('/settings', ['timezone' => 'UTC', 'region' => 'us', 'alerts_enabled' => 1, 'alert_buy_target' => 0, 'alert_sell_target' => 1, 'alert_profit' => 1])
            ->assertSessionDoesntHaveErrors();

        $u = $user->fresh();
        $this->assertSame([true, false, true, true], [$u->alerts_enabled, $u->alert_buy_target, $u->alert_sell_target, $u->alert_profit]);

        $this->get('/settings')->assertSee('Price alert emails')->assertSee('A position moves into profit');
        $this->patch('/settings', ['timezone' => 'UTC', 'region' => 'us'])->assertSessionDoesntHaveErrors();
        $this->assertTrue($user->fresh()->alerts_enabled, 'a post without the fields leaves them alone');
        $this->patch('/settings', ['timezone' => 'UTC', 'region' => 'us', 'alerts_enabled' => 'maybe'])->assertSessionHasErrors('alerts_enabled');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        FetchRun::query()->delete();

        parent::tearDown();
    }
}
