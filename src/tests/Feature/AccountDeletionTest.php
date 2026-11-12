<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CallsApi;
use Tests\Support\Fixtures;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use CallsApi;
    use Fixtures, RefreshDatabase;

    private const USER_TABLES = ['uploads', 'tsm_sales', 'tsm_expired', 'tsm_cancelled', 'tsm_buy_candidates', 'inventory'];

    public function test_deleting_an_account_removes_its_data_and_tokens_but_not_other_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        foreach ([$alice, $bob] as $user) {
            $this->postIngest($this->fixture('tsm/TradeSkillMaster.lua'), $user->issueIngestToken('pc'))->assertOk();
        }

        $bobRows = array_map(fn ($t) => DB::table($t)->where('user_id', $bob->id)->count(), self::USER_TABLES);
        $this->assertGreaterThan(0, min($bobRows));

        // The API calls above switched the default guard to sanctum for this app instance;
        // real requests are separate processes, so reset it for the web request.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($alice)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertNull(User::find($alice->id));

        foreach (self::USER_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $alice->id)->count(), $table);
        }

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $alice->id)->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $bob->id)->count());
        $this->assertSame($bobRows, array_map(fn ($t) => DB::table($t)->where('user_id', $bob->id)->count(), self::USER_TABLES));
    }

    public function test_a_deleted_users_token_no_longer_authenticates(): void
    {
        $user = User::factory()->create();
        $token = $user->issueIngestToken('pc');

        $user->delete();

        $this->postIngest('', $token)->assertUnauthorized();
    }
}
