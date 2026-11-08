<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PositionsService;
use App\Services\ScoreboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LoadsLedgerFixture;
use Tests\TestCase;

class ScoreboardTest extends TestCase
{
    use LoadsLedgerFixture;
    use RefreshDatabase;

    private function join(User $user, string $nick = 'Flipper One'): void
    {
        $user->forceFill(['scoreboard_opt_in' => true, 'scoreboard_nickname' => $nick])->save();
    }

    private function save(User $user, array $data)
    {
        return $this->actingAs($user)->from('/profile')->patch('/profile/scoreboard', $data);
    }

    public function test_opt_in_needs_a_valid_unique_nickname(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->join($other, 'Taken Name');

        $this->save($user, ['scoreboard_opt_in' => 1])->assertSessionHasErrors('scoreboard_nickname');
        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'ab'])->assertSessionHasErrors('scoreboard_nickname');
        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => str_repeat('a', 25)])->assertSessionHasErrors('scoreboard_nickname');
        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'bad<tag>'])->assertSessionHasErrors('scoreboard_nickname');
        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'TAKEN name'])->assertSessionHasErrors('scoreboard_nickname');
        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'Ad.min'])->assertSessionHasErrors('scoreboard_nickname');
        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'DEMO'])->assertSessionHasErrors('scoreboard_nickname');
        $this->assertFalse($user->fresh()->scoreboard_opt_in);

        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => '  Gold_Goblin-7  '])->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertTrue($user->fresh()->scoreboard_opt_in);
        $this->assertSame('Gold_Goblin-7', $user->fresh()->scoreboard_nickname);

        // Keeping your own nickname (different case) is not a clash.
        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'gold_goblin-7'])->assertSessionHasNoErrors();
    }

    public function test_the_form_needs_a_verified_account_and_never_the_demo(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->patch('/profile/scoreboard', ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'Nope Nope'])
            ->assertRedirect(route('verification.notice'));

        $demo = User::factory()->create(['is_demo' => true]);
        $this->actingAs($demo)->patch('/profile/scoreboard', ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'Demo Guy']);
        $this->assertFalse($demo->fresh()->scoreboard_opt_in);
        $this->get('/profile')->assertOk();
    }

    public function test_opting_in_builds_entries_that_match_the_portfolio(): void
    {
        $user = $this->fixtureUser();
        $this->save($user, ['scoreboard_opt_in' => 1, 'scoreboard_nickname' => 'Flipper One'])->assertSessionHasNoErrors();

        $portfolio = app(PositionsService::class)->forUser($user);
        $all = DB::table('scoreboard_entries')->where('user_id', $user->id)->where('game', 'retail')->where('period', 'all')->first();

        $this->assertSame($portfolio->summary['realized'], (int) $all->pnl_copper);
        $this->assertSame(count($portfolio->realizedEvents), (int) $all->flips);
        $this->assertSame(
            (int) DB::table('tsm_sales')->where('user_id', $user->id)->selectRaw('sum(qty * unit_price_copper) as s')->value('s'),
            (int) $all->sales_copper,
        );
        $this->assertSame(3, DB::table('scoreboard_entries')->where('user_id', $user->id)->count());

        $rows = app(ScoreboardService::class)->board('retail', 'all', 'pnl');
        $this->assertSame('Flipper One', $rows[0]['nickname']);
        $this->assertSame(1, $rows[0]['rank']);
    }

    public function test_the_seven_day_window_is_anchored_at_now(): void
    {
        $user = $this->fixtureUser();
        $events = app(PositionsService::class)->forUser($user)->realizedEvents;
        $this->assertNotEmpty($events);
        $last = max(array_column($events, 0));
        $this->join($user);

        $board = app(ScoreboardService::class);
        $board->rebuildFor($user, $last + 3600);
        $in7 = array_filter($events, fn ($e) => $e[0] >= $last + 3600 - 7 * 86400);
        $row = DB::table('scoreboard_entries')->where(['user_id' => $user->id, 'period' => '7d'])->first();
        $this->assertSame(array_sum(array_column($in7, 1)), (int) $row->pnl_copper);
        $this->assertSame(count($in7), (int) $row->flips);

        // Far in the future the 7 day window is empty, all time is not.
        $board->rebuildFor($user, $last + 100 * 86400);
        $this->assertSame(0, (int) DB::table('scoreboard_entries')->where(['user_id' => $user->id, 'period' => '7d'])->value('units'));
        $this->assertSame([], $board->board('retail', '7d', 'pnl'));
    }

    public function test_opting_out_hides_at_once_and_deletes_rows(): void
    {
        $user = $this->fixtureUser();
        $this->join($user);
        app(ScoreboardService::class)->rebuildFor($user);
        $this->assertNotEmpty(app(ScoreboardService::class)->board('retail', 'all', 'pnl'));

        // Read-time filter: hidden even if the rows are still there.
        $user->forceFill(['scoreboard_opt_in' => false])->save();
        $this->assertSame([], app(ScoreboardService::class)->board('retail', 'all', 'pnl'));

        $this->join($user);
        $this->save($user, ['scoreboard_opt_in' => 0, 'scoreboard_nickname' => 'Flipper One']);
        $this->assertFalse($user->fresh()->scoreboard_opt_in);
        $this->assertSame(0, DB::table('scoreboard_entries')->where('user_id', $user->id)->count());
    }

    public function test_page_shows_the_nickname_only_and_never_the_demo(): void
    {
        $user = $this->fixtureUser(attributes: ['name' => 'Real Personname', 'email' => 'real@example.com']);
        $this->join($user);
        $demo = $this->fixtureUser(attributes: ['is_demo' => true, 'name' => 'Demo Person', 'email' => 'demo@example.com']);
        $demo->forceFill(['scoreboard_opt_in' => true, 'scoreboard_nickname' => 'Demo Nick'])->save();
        $nobody = $this->fixtureUser(attributes: ['name' => 'Private Person']);
        $svc = app(ScoreboardService::class);
        $svc->rebuildFor($user);
        $svc->rebuildFor($demo);
        $svc->rebuildFor($nobody);

        $html = $this->get('/scoreboard?period=all')->assertOk()->assertSee('Flipper One')->getContent();

        foreach (['Real Personname', 'real@example.com', 'Demo Nick', 'Demo Person', 'Private Person'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }

        $this->actingAs($user)->get('/scoreboard?period=all')->assertSee('you');
    }

    public function test_bad_query_values_fall_back(): void
    {
        $user = $this->fixtureUser();
        $this->join($user);
        app(ScoreboardService::class)->rebuildFor($user, time());

        $this->get('/scoreboard?game=nope&period=zzz&metric=<script>')->assertOk()->assertDontSee('<script>', false);
        $this->get('/scoreboard?game[]=x&period[]=7d')->assertOk();
    }

    public function test_rebuild_drops_users_who_left_and_skips_the_demo(): void
    {
        $in = $this->fixtureUser();
        $this->join($in);
        $gone = $this->fixtureUser();
        $svc = app(ScoreboardService::class);
        $svc->rebuildFor($gone);
        $this->assertSame(3, DB::table('scoreboard_entries')->where('user_id', $gone->id)->count());

        $this->assertSame(1, $svc->rebuild());
        $this->assertSame(0, DB::table('scoreboard_entries')->where('user_id', $gone->id)->count());
        $this->assertSame(3, DB::table('scoreboard_entries')->where('user_id', $in->id)->count());
        $this->artisan('scoreboard:build')->assertSuccessful();
    }
}
