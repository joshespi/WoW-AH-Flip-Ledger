<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CombinedService;
use App\Services\IngestService;
use App\Services\TradesService;
use App\Support\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AddonFile;
use Tests\Support\SeedsUserRows;
use Tests\TestCase;

class GameViewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsUserRows;

    private const T = AddonFile::T0;

    private function user(array $games = ['retail', 'forever']): User
    {
        return User::factory()->create(['enabled_games' => implode(',', $games)]);
    }

    private function buy(User $user, string $game, int $item, int $qty, int $cost): void
    {
        app(TradesService::class)->add($user, $item, $qty, $cost, self::T, null, game: $game);
    }

    public function test_the_switch_is_stored_and_read_back(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post(route('game.switch'), ['game' => 'forever'])->assertRedirect();

        $this->assertSame('forever', $user->fresh()->game_view);
        $this->assertSame('forever', $user->fresh()->activeGame());
    }

    public function test_an_unknown_game_is_rejected(): void
    {
        $this->actingAs($this->user())->post(route('game.switch'), ['game' => 'wotlk'])->assertSessionHasErrors('game');
    }

    public function test_both_works_on_retail_where_a_page_cannot_mix(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post(route('game.switch'), ['game' => 'all']);

        $user = $user->fresh();
        $this->assertTrue($user->viewsAll());
        $this->assertSame(Game::RETAIL, $user->activeGame());
    }

    public function test_a_read_only_session_can_still_switch_but_nothing_is_saved(): void
    {
        $user = User::factory()->create(['is_demo' => true, 'enabled_games' => 'retail,forever']);

        $this->actingAs($user)->post(route('game.switch'), ['game' => 'forever'])->assertRedirect();

        $this->assertSame('retail', $user->fresh()->game_view);
        $this->assertSame('forever', $this->app['session']->get('game_view'));
    }

    public function test_the_dashboard_shows_only_the_chosen_games_ledger(): void
    {
        $user = $this->user();
        $this->buy($user, Game::RETAIL, 111, 10, 1000);
        $this->buy($user, Game::FOREVER, 222, 5, 500);

        $user->forceFill(['game_view' => 'forever'])->save();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-game="forever"', false)
            ->assertSee('Forever')
            ->assertDontSee('Item #111');
    }

    public function test_the_combined_dashboard_splits_both_games_and_tags_each_row(): void
    {
        $user = $this->user();
        $this->buy($user, Game::RETAIL, 111, 10, 1000);
        $this->buy($user, Game::FOREVER, 222, 5, 500);
        $user->forceFill(['game_view' => 'all'])->save();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Games side by side')
            ->assertSee('Item #111')
            ->assertSee('Item #222');
    }

    public function test_the_combined_service_sums_games_without_merging_rows(): void
    {
        $user = $this->user();
        $this->buy($user, Game::RETAIL, 111, 10, 1000);
        $this->buy($user, Game::FOREVER, 111, 4, 400);

        $data = app(CombinedService::class)->forUser($user, self::T + 86400);

        $this->assertSame(1400, $data['summary']['total']['stock_cost']);
        $this->assertCount(2, $data['rows']);
        $this->assertSame([Game::FOREVER, Game::RETAIL], collect($data['rows'])->pluck('game')->sort()->values()->all());
    }

    public function test_every_series_shares_one_axis(): void
    {
        $user = $this->user();
        $this->seedSale($user, 5, 1, 100, '2026-10-01 00:00:00', Game::FOREVER);
        $this->buy($user, Game::FOREVER, 5, 1, 50);

        $ranges = app(CombinedService::class)->forUser($user, strtotime('2026-11-05 12:00:00'))['ranges'];

        foreach ($ranges as $r) {
            $this->assertCount(count($r['labels']), $r['cum']['retail']);
            $this->assertCount(count($r['labels']), $r['cum']['forever']);
            $this->assertCount(count($r['labels']), $r['cum']['total']);
        }
    }

    public function test_the_trades_page_only_lists_the_active_games_buys(): void
    {
        $user = $this->user();
        $this->buy($user, Game::RETAIL, 111, 10, 1000);
        $this->buy($user, Game::FOREVER, 222, 5, 500);

        $this->actingAs($user)->get(route('trades.index'))->assertOk()->assertSee('111')->assertDontSee('222');

        $user->forceFill(['game_view' => 'forever'])->save();
        $this->actingAs($user->fresh())->get(route('trades.index'))->assertOk()->assertSee('222');
    }

    public function test_a_user_who_plays_one_game_sees_no_switcher_or_picker(): void
    {
        $user = $this->user(['retail']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('name="game"', false);
        $this->actingAs($user)->get(route('uploads.index'))->assertOk()
            ->assertDontSee('Which game is this file from?')
            ->assertSee('<input type="hidden" name="game" value="retail">', false);
    }

    public function test_a_game_that_is_not_enabled_cannot_be_picked_or_viewed(): void
    {
        $user = $this->user(['retail']);

        $this->actingAs($user)->post(route('game.switch'), ['game' => 'forever'])->assertSessionHasErrors('game');

        // A stale stored view of a game that was switched off falls back to the first enabled game.
        $user->forceFill(['game_view' => 'forever'])->save();
        $this->assertSame('retail', $user->fresh()->gameView());
    }

    public function test_all_needs_more_than_one_enabled_game(): void
    {
        $user = $this->user(['retail']);
        $user->forceFill(['game_view' => 'all'])->save();

        $this->assertFalse($user->fresh()->viewsAll());
    }

    public function test_settings_turn_games_on_and_off_and_keep_at_least_one(): void
    {
        $user = $this->user(['retail']);
        $base = ['timezone' => 'UTC', 'region' => 'us', 'games_form' => 1];

        $this->actingAs($user)->patch(route('settings.update'), $base + ['games' => ['retail', 'classic']])->assertSessionHasNoErrors();
        $this->assertSame(['retail', 'classic'], $user->fresh()->enabledGames());

        $this->actingAs($user)->patch(route('settings.update'), $base)->assertSessionHasErrors('games');
        $this->assertSame(['retail', 'classic'], $user->fresh()->enabledGames());

        // A partial post (no marker) leaves the games alone.
        $this->actingAs($user)->patch(route('settings.update'), ['timezone' => 'UTC', 'region' => 'us'])->assertSessionHasNoErrors();
        $this->assertSame(['retail', 'classic'], $user->fresh()->enabledGames());
    }

    public function test_an_upload_for_a_game_turns_it_on(): void
    {
        $user = $this->user(['retail']);

        app(IngestService::class)->ingest(
            $user,
            AddonFile::make(['sale|'.(self::T + 100).'|Area52|238371|5|2000|100']),
            'uploader', self::T + 86400, game: Game::CLASSIC,
        );

        $this->assertSame(['retail', 'classic'], $user->fresh()->enabledGames());
        $this->assertSame(1, DB::table('tsm_sales')->where('game', 'classic')->count());
    }

    public function test_the_combined_view_covers_exactly_the_enabled_games(): void
    {
        $user = $this->user(['retail', 'classic']);
        $this->buy($user, Game::RETAIL, 111, 10, 1000);
        $this->buy($user, Game::CLASSIC, 333, 5, 500);
        $this->buy($user, Game::FOREVER, 222, 5, 500);

        $data = app(CombinedService::class)->forUser($user, self::T + 86400);

        $this->assertSame(['retail', 'classic'], array_keys($data['portfolios']));
        $this->assertSame(1500, $data['summary']['total']['stock_cost']);
    }
}
