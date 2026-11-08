<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Utc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IngestsFiles;
use Tests\Support\TsmFile;
use Tests\TestCase;

class RecordActivityTest extends TestCase
{
    use IngestsFiles;
    use RefreshDatabase;

    /** @return list<string> */
    private function days(User $user): array
    {
        return DB::table('user_activity_days')->where('user_id', $user->id)->pluck('day')->map(fn ($d) => substr($d, 0, 10))->all();
    }

    public function test_web_requests_record_the_utc_day_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard');
        $this->actingAs($user)->get('/settings');

        $this->assertSame([Utc::day()], $this->days($user));
    }

    public function test_an_upload_records_activity_but_the_uploaders_polls_do_not(): void
    {
        $user = User::factory()->create();
        $token = $user->issueIngestToken('pc');
        $this->app['auth']->forgetGuards();

        // Unattended: the uploader pings and polls whether anyone plays.
        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->getJson('/api/v1/addon-feed', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame([], $this->days($user));

        $this->postIngest(TsmFile::make(), $token)->assertOk();
        $this->assertSame([Utc::day()], $this->days($user));
    }

    public function test_guests_the_demo_and_impersonation_are_not_recorded(): void
    {
        $this->get('/about')->assertOk();
        $this->assertSame(0, DB::table('user_activity_days')->count());

        $demo = User::factory()->create(['is_demo' => true]);
        $this->actingAs($demo)->get('/dashboard');
        $this->assertSame([], $this->days($demo));

        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.impersonate', $alice->id));
        $this->get('/dashboard');

        $this->assertSame([], $this->days($alice));
    }

    public function test_rows_go_with_the_user_and_old_ones_are_pruned(): void
    {
        $user = User::factory()->create();
        DB::table('user_activity_days')->insert([
            ['user_id' => $user->id, 'day' => Utc::day(time() - 91 * 86400)],
            ['user_id' => $user->id, 'day' => Utc::day(time() - 89 * 86400)],
        ]);

        $this->artisan('stats:prune')->assertSuccessful();

        $this->assertCount(1, $this->days($user));

        $user->delete();
        $this->assertSame(0, DB::table('user_activity_days')->count());
    }
}
