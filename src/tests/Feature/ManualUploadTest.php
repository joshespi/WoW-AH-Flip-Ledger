<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Utc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\TsmFile;
use Tests\TestCase;

class ManualUploadTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    private function file(string $content, string $name = 'TradeSkillMaster.lua'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_guests_and_unverified_users_cannot_reach_uploads(): void
    {
        $this->get('/uploads')->assertRedirect('/login');
        $this->post('/uploads', [])->assertRedirect('/login');

        $this->actingAs(User::factory()->unverified()->create())->get('/uploads')->assertRedirect('/verify-email');
    }

    public function test_a_verified_user_can_upload_and_see_the_result(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Denver']);

        $this->actingAs($user)
            ->post('/uploads', ['file' => $this->file($this->fixture('tsm/TradeSkillMaster.lua')), 'file_mtime' => TsmFile::T0 * 1000])
            ->assertRedirect('/uploads')
            ->assertSessionHas('status', 'upload-ok');

        $this->assertGreaterThan(500, DB::table('tsm_sales')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('uploads', [
            'user_id' => $user->id, 'source' => 'manual', 'status' => 'ok',
            'file_mtime' => Utc::sql(TsmFile::T0),
        ]);

        $this->actingAs($user)->get('/uploads')->assertOk()->assertSee('manual')->assertSee('OK');
    }

    public function test_the_chosen_game_decides_where_a_manual_upload_lands(): void
    {
        $user = User::factory()->create();
        $content = TsmFile::make(sales: [['i:100', 5, 200, TsmFile::T0, 'Auction']]);

        $this->actingAs($user)->post('/uploads', ['file' => $this->file($content), 'game' => 'forever'])
            ->assertSessionHas('status', 'upload-ok');

        $this->assertSame(1, DB::table('tsm_sales')->where('user_id', $user->id)->where('game', 'forever')->count());
        $this->assertSame(0, DB::table('tsm_sales')->where('user_id', $user->id)->where('game', 'retail')->count());
        $this->assertDatabaseHas('uploads', ['user_id' => $user->id, 'game' => 'forever']);

        $this->actingAs($user)->get('/uploads')->assertOk()->assertSee('Which game is this file from?')->assertSee('Forever');
    }

    public function test_an_unknown_game_is_refused_on_upload(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/uploads', ['file' => $this->file(TsmFile::make()), 'game' => 'wotlk'])
            ->assertSessionHasErrors('game');
    }

    public function test_a_gzipped_manual_upload_works(): void
    {
        $user = User::factory()->create();
        $gz = gzencode(TsmFile::make(sales: [['i:100', 5, 200, TsmFile::T0, 'Auction']]));

        $this->actingAs($user)->post('/uploads', ['file' => $this->file($gz, 'TradeSkillMaster.lua.gz')])
            ->assertSessionHas('status', 'upload-ok');

        $this->assertSame(1, DB::table('tsm_sales')->where('user_id', $user->id)->count());
    }

    public function test_a_bad_file_shows_an_error_and_is_recorded_as_failed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/uploads')->post('/uploads', ['file' => $this->file("print('hi')")])
            ->assertRedirect('/uploads')
            ->assertSessionHasErrors('file');

        $this->assertDatabaseHas('uploads', ['user_id' => $user->id, 'status' => 'failed']);
        $this->actingAs($user)->get('/uploads')->assertSee('Failed');
    }

    public function test_the_file_is_required_and_the_mtime_must_be_a_number(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/uploads', [])->assertSessionHasErrors('file');
        $this->actingAs($user)->post('/uploads', ['file' => $this->file(TsmFile::make()), 'file_mtime' => 'yesterday'])
            ->assertSessionHasErrors('file_mtime');
    }

    public function test_the_history_only_lists_your_own_uploads(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($bob)->post('/uploads', ['file' => $this->file("print('bob only')")]);

        $this->actingAs($alice)->get('/uploads')->assertOk()->assertSee('Nothing uploaded yet.');
        $this->assertSame(1, DB::table('uploads')->where('user_id', $bob->id)->count());
    }

    public function test_manual_uploads_are_throttled_per_user(): void
    {
        config(['ledger.ingest.manual_per_user_per_hour' => 2]);
        $user = User::factory()->create();

        foreach (range(1, 2) as $_) {
            $this->actingAs($user)->post('/uploads', ['file' => $this->file(TsmFile::make())])->assertRedirect('/uploads');
        }

        $this->actingAs($user)->post('/uploads', ['file' => $this->file(TsmFile::make())])->assertStatus(429);
    }
}
