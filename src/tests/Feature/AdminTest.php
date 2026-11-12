<?php

namespace Tests\Feature;

use App\Livewire\Trades as TradesComponent;
use App\Models\FetchRun;
use App\Models\Trade;
use App\Models\Upload;
use App\Models\User;
use App\Services\TradesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesBlizzard;
use Tests\Support\SeedsMarketData;
use Tests\Support\SeedsUserRows;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use FakesBlizzard;
    use RefreshDatabase;
    use SeedsMarketData;
    use SeedsUserRows;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email' => 'boss@example.com']);
    }

    private function upload(User $user, string $status, ?string $error = null, ?array $counts = null): Upload
    {
        return $this->seedUpload($user, $status, ['source' => Upload::SOURCE_UPLOADER, 'client_version' => '0.1.0', 'bytes' => 2048, 'error' => $error, 'counts' => $counts]);
    }

    public function test_grant_and_revoke_from_the_command_line(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);

        $this->artisan('admin:grant a@example.com')->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('admin:grant a@example.com --revoke')->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);

        $this->artisan('admin:grant nobody@example.com')->assertFailed();

        $this->artisan('demo:seed')->assertSuccessful();
        $this->artisan('admin:grant demo@demo.invalid')->assertFailed();
    }

    public function test_admin_cannot_be_set_from_the_web(): void
    {
        $this->post('/register', ['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'long-enough-pass', 'password_confirmation' => 'long-enough-pass', 'is_admin' => 1]);

        $this->assertFalse((bool) User::where('email', 'eve@example.com')->value('is_admin'));
        $this->assertFalse(User::create(['name' => 'x', 'email' => 'x@example.com', 'password' => 'long-enough-pass', 'is_admin' => true])->fresh()->is_admin);
    }

    public function test_only_admins_get_in_and_everyone_else_sees_a_404(): void
    {
        $urls = ['/admin', '/admin/users', '/admin/uploads', '/admin/fetch'];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        $this->actingAs(User::factory()->create());

        foreach ($urls as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->post('/admin/users/1/impersonate')->assertNotFound();

        $this->actingAs($this->admin());

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/dashboard')->assertSee(route('admin.overview'), false);
    }

    public function test_nav_link_is_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertDontSee(route('admin.overview'), false);
    }

    public function test_overview_shows_counts_failures_and_fetch_health(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->artisan('demo:seed');
        $this->upload($user, Upload::STATUS_OK, null, ['sales' => ['seen' => 3, 'new' => 3], 'skipped' => 0]);
        $this->upload($user, Upload::STATUS_FAILED, 'Not a TSM file');
        $this->seedFetchRun('ok', time() - 600, time() - 900);
        $this->seedFetchRun('failed', time() - 60);
        FetchRun::where('status', 'failed')->update(['error' => 'Blizzard said no']);

        $res = $this->actingAs($admin)->get('/admin')->assertOk();

        $res->assertSee('Not a TSM file')->assertSee('Blizzard said no')->assertSee('Stale or failing')->assertSee('1 failed');
        $this->assertStringNotContainsString('demo@demo.invalid', $res->getContent(), 'demo is not counted as a user');
    }

    public function test_fetch_health_flags_a_fresh_good_run_as_healthy_when_configured(): void
    {
        config(['services.blizzard.client_id' => 'id', 'services.blizzard.client_secret' => 'secret']);
        $this->seedFetchRun('ok', time() - 300, time() - 600);

        $this->actingAs($this->admin())->get('/admin/fetch')->assertOk()->assertSee('Healthy');
    }

    public function test_manual_fetch_is_admin_only_and_reports_the_result(): void
    {
        $this->blizzardUnconfigured();

        $this->actingAs(User::factory()->create())->post('/admin/fetch')->assertNotFound();

        $this->actingAs($this->admin())->post('/admin/fetch')->assertRedirect('/admin/fetch')->assertSessionHas('fetch_error');
        $this->get('/admin/fetch')->assertOk()->assertSee('Fetch now');
    }

    public function test_users_page_searches_counts_and_hides_impersonation_for_protected_accounts(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create(['name' => 'Alice Wong', 'email' => 'alice@example.com']);
        User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $this->upload($alice, Upload::STATUS_OK);
        $this->upload($alice, Upload::STATUS_FAILED, 'x');
        app(TradesService::class)->add($alice, 100, 1, 1, time() - 60, null);
        $this->artisan('demo:seed');

        $this->actingAs($admin)->get('/admin/users?q=ALICE')->assertOk()->assertSee('alice@example.com')->assertDontSee('bob@example.com')
            ->assertSee(route('admin.impersonate', $alice->id), false)->assertDontSee(route('admin.impersonate', $admin->id), false);

        $this->get('/admin/users?q=%25')->assertSee('No users match');
        $this->get('/admin/users')->assertSee('bob@example.com');

        $demo = User::where('is_demo', true)->first();
        $this->get('/admin/users')->assertDontSee(route('admin.impersonate', $demo->id), false);
    }

    public function test_uploads_page_lists_everyones_uploads_and_filters_failures(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $this->upload($alice, Upload::STATUS_OK, null, ['sales' => ['seen' => 5, 'new' => 4], 'skipped' => 1]);
        $this->upload($alice, Upload::STATUS_FAILED, 'Broken file');

        $this->actingAs($admin)->get('/admin/uploads')->assertSee('alice@example.com')->assertSee('Broken file')->assertSee('4 sales');
        $this->get('/admin/uploads?failed=1')->assertSee('Broken file')->assertDontSee('4 sales');
    }

    public function test_impersonation_round_trip_is_read_only_and_audited(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create(['email' => 'alice@example.com', 'timezone' => 'UTC']);
        app(TradesService::class)->add($alice, 100, 5, 5000, time() - 60, 'ALICE-NOTE');

        $this->actingAs($admin)->post(route('admin.impersonate', $alice->id))->assertRedirect('/dashboard');

        $this->assertSame($alice->id, Auth::id());
        $this->assertSame($admin->id, session('impersonator_id'));
        $this->assertDatabaseHas('admin_audit_logs', ['admin_id' => $admin->id, 'action' => 'impersonate.start', 'target_user_id' => $alice->id, 'target_email' => 'alice@example.com']);

        $this->get('/dashboard')->assertOk()->assertSee('Viewing as')->assertSee('alice@example.com');
        $this->get('/trades')->assertOk()->assertSee('ALICE-NOTE');

        // Read-only: ordinary writes, the account export and the admin area are all closed.
        $this->from('/settings')->patch('/settings', ['timezone' => 'Asia/Tokyo', 'region' => 'us'])->assertSessionHas('read_only_blocked');
        $this->post('/settings/tokens', ['name' => 'x'])->assertSessionHas('read_only_blocked');
        $this->delete('/profile', ['password' => 'password'])->assertSessionHas('read_only_blocked');
        $this->get('/settings/export')->assertForbidden();
        $this->get('/trades/export')->assertForbidden();
        $this->get('/admin')->assertNotFound();
        $this->assertSame('UTC', $alice->fresh()->timezone);
        $this->assertSame(0, $alice->tokens()->count());
        $this->assertDatabaseHas('users', ['id' => $alice->id]);

        $this->post(route('impersonate.stop'))->assertRedirect(route('admin.users'));

        $this->assertSame($admin->id, Auth::id());
        $this->assertNull(session('impersonator_id'));
        $this->assertDatabaseHas('admin_audit_logs', ['admin_id' => $admin->id, 'action' => 'impersonate.stop', 'target_user_id' => $alice->id]);
        $this->get('/admin')->assertOk()->assertSee('impersonate.start');
    }

    public function test_component_writes_are_refused_while_impersonating(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create();

        $session = new Store('test', new ArraySessionHandler(10));
        $session->put('impersonator_id', $admin->id);
        $request = Request::create('/');
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        Auth::login($alice);

        $this->assertTrue($alice->readOnly());
        $this->assertFalse($admin->readOnly(), 'only the viewed account is read-only');

        // Every write action goes through ReportsErrors::attempt, which refuses before it runs.
        $component = new TradesComponent;
        $component->item = 'i:100';
        $component->qty = '1';
        $component->cost = '1';
        $component->time = $alice->inputTime();
        $component->save(app(TradesService::class));

        $this->assertStringContainsString('admin', $component->getErrorBag()->first('form'));
        $this->assertSame(0, Trade::count());
    }

    public function test_protected_accounts_cannot_be_viewed_as(): void
    {
        $admin = $this->admin();
        $other = User::factory()->admin()->create();
        $this->artisan('demo:seed');
        $demo = User::where('is_demo', true)->first();

        foreach ([$other, $demo, $admin] as $target) {
            $this->actingAs($admin)->from('/admin/users')->post(route('admin.impersonate', $target->id))->assertSessionHasErrors('impersonate');
            $this->assertSame($admin->id, Auth::id());
            $this->assertNull(session('impersonator_id'));
        }

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_unverified_accounts_cannot_be_viewed_as(): void
    {
        $admin = $this->admin();
        $new = User::factory()->unverified()->create();

        $this->actingAs($admin)->get('/admin/users')->assertDontSee(route('admin.impersonate', $new->id), false);
        $this->from('/admin/users')->post(route('admin.impersonate', $new->id))->assertSessionHasErrors('impersonate');

        $this->assertSame($admin->id, Auth::id());
        $this->assertNull(session('impersonator_id'));
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_stop_without_impersonating_is_a_404(): void
    {
        $this->actingAs(User::factory()->create())->post(route('impersonate.stop'))->assertNotFound();
    }

    public function test_stop_after_the_admin_lost_their_role_signs_out(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.impersonate', $alice->id));
        $admin->forceFill(['is_admin' => false])->save();

        $this->post(route('impersonate.stop'))->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logging_out_while_impersonating_ends_everything(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.impersonate', $alice->id));
        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull(session('impersonator_id'));
        $this->assertSame(1, DB::table('admin_audit_logs')->count());
    }
}
