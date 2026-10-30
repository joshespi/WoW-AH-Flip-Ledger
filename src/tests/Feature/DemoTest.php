<?php

namespace Tests\Feature;

use App\Livewire\Market;
use App\Livewire\Reconcile;
use App\Livewire\Trades;
use App\Models\Trade;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\TradesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;

    private function demo(): User
    {
        $this->artisan('demo:seed')->assertSuccessful();

        return User::where('is_demo', true)->sole();
    }

    public function test_seed_builds_a_verified_demo_account_with_data_and_is_repeatable(): void
    {
        $demo = $this->demo();

        $this->assertNotNull($demo->email_verified_at);
        $this->assertGreaterThan(0, Trade::where('user_id', $demo->id)->count());
        $this->assertGreaterThan(0, DB::table('tsm_sales')->where('user_id', $demo->id)->count());
        $this->assertGreaterThan(0, WatchlistItem::where('user_id', $demo->id)->count());

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(1, User::where('is_demo', true)->count());
        $this->assertSame(Trade::where('user_id', User::where('is_demo', true)->value('id'))->count(), Trade::count());
    }

    public function test_nobody_can_sign_in_to_the_demo_with_a_password(): void
    {
        $demo = $this->demo();

        foreach (['password', 'demo', 'Password1!'] as $guess) {
            $this->post('/login', ['email' => $demo->email, 'password' => $guess])->assertSessionHasErrors();
            $this->assertGuest();
        }
    }

    public function test_demo_button_signs_in_and_the_screens_render(): void
    {
        $this->demo();

        $this->get('/')->assertSee('Try the demo');
        $this->post('/demo')->assertRedirect('/dashboard');

        foreach (['/dashboard', '/positions', '/trades', '/reconcile', '/market', '/scan', '/uploads', '/settings'] as $url) {
            $this->get($url)->assertOk()->assertSee('You are viewing the demo');
        }
    }

    public function test_button_is_hidden_and_route_404s_without_a_demo(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Try the demo');
        $this->post('/demo')->assertNotFound();
    }

    public function test_signed_in_users_are_not_switched_to_the_demo(): void
    {
        $this->demo();

        $this->actingAs(User::factory()->create())->post('/demo')->assertRedirect('/dashboard');
        $this->assertFalse(auth()->user()->is_demo);
    }

    public function test_ordinary_posts_are_refused_for_the_demo(): void
    {
        $demo = $this->demo();
        $before = [$demo->timezone, Trade::count()];

        $this->actingAs($demo);

        $this->from('/settings')->patch('/settings', ['timezone' => 'Asia/Tokyo', 'region' => 'us'])->assertRedirect('/settings')->assertSessionHas('read_only_blocked');
        $this->post('/settings/tokens', ['name' => 'x'])->assertSessionHas('read_only_blocked');
        $this->post('/setup/token', ['name' => 'x'])->assertSessionHas('read_only_blocked');
        $this->post('/trades/import', ['csv_text' => "time,item,qty,cost\n2026-10-12T10:51,i:1,1,1\n"])->assertSessionHas('read_only_blocked');
        $this->delete('/profile', ['password' => 'x'])->assertSessionHas('read_only_blocked');

        $this->assertSame($before, [$demo->fresh()->timezone, Trade::count()]);
        $this->assertSame(0, $demo->tokens()->count());
        $this->get('/settings')->assertSee('The demo is read-only.');
    }

    public function test_livewire_writes_are_refused_by_the_services(): void
    {
        $demo = $this->demo();
        $trade = Trade::where('user_id', $demo->id)->first();
        $watch = WatchlistItem::where('user_id', $demo->id)->first();
        $count = Trade::count();

        Livewire::actingAs($demo)->test(Trades::class)
            ->set('item', 'i:'.$trade->item_id)->set('qty', '1')->set('cost', '1g')->call('save')->assertHasErrors('form')->assertSee('The demo is read-only')
            ->call('delete', $trade->id)->assertHasErrors('form')
            ->set('pasted', 'You won an auction for [Thing]x1 for 1g')->call('addPasted')->assertHasErrors('pasted');

        Livewire::actingAs($demo)->test(Market::class)
            ->set('item', 'i:100')->call('add')->assertHasErrors('add')
            ->call('remove', $watch->id)->assertHasErrors('list')
            ->call('move', $watch->id, 1)->assertHasErrors('list');

        DB::table('tsm_buy_candidates')->insert(['user_id' => $demo->id, 'tsm_ref' => sha1('x'), 'realm' => 'R', 'item_id' => $trade->item_id, 'qty' => 5, 'cost_copper' => 5, 'at' => '2020-01-01 00:00:00', 'status' => 'pending']);
        $id = DB::table('tsm_buy_candidates')->value('id');

        Livewire::actingAs($demo)->test(Reconcile::class)->call('accept', $id)->assertHasErrors('row'.$id)->call('ignore', $id)->assertHasErrors('row'.$id);

        $this->assertSame($count, Trade::count());
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->value('status'));
        $this->assertTrue(WatchlistItem::whereKey($watch->id)->exists());
    }

    public function test_reads_and_leaving_still_work_for_the_demo(): void
    {
        $this->demo();
        $this->post('/demo');

        $this->get('/settings/export')->assertOk();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_real_users_are_unaffected(): void
    {
        $this->demo();
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings', ['timezone' => 'Asia/Tokyo', 'region' => 'us'])->assertSessionDoesntHaveErrors();
        $this->assertSame('Asia/Tokyo', $user->fresh()->timezone);
        $this->assertNotNull(app(TradesService::class)->add($user, 100, 1, 1, time(), null));
    }

    public function test_is_demo_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'x', 'email' => 'x@example.com', 'password' => 'secret-secret', 'is_demo' => true]);

        $this->assertFalse($user->fresh()->is_demo);
    }
}
