<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PingApiTest extends TestCase
{
    use RefreshDatabase;

    private function ping(?string $token, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/ping', $headers + ($token === null ? [] : ['Authorization' => 'Bearer '.$token]));
    }

    public function test_a_valid_token_gets_ok_and_the_min_version(): void
    {
        $token = User::factory()->create()->issueIngestToken('pc');

        $this->ping($token)->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('min_client_version', '0.0.0')
            ->assertJsonPath('last_upload', null)
            ->assertJsonStructure(['server_time']);
    }

    public function test_it_reports_only_the_token_owners_last_upload(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        Upload::create(['user_id' => $bob->id, 'source' => 'manual', 'received_at' => now(), 'bytes' => 1, 'status' => 'failed']);
        Upload::create(['user_id' => $alice->id, 'source' => 'uploader', 'received_at' => now()->subHour(), 'bytes' => 1, 'status' => 'ok']);

        $this->ping($alice->issueIngestToken('pc'))->assertOk()->assertJsonPath('last_upload.status', 'ok');
    }

    public function test_it_needs_a_token_with_the_ingest_ability(): void
    {
        $user = User::factory()->create();

        $this->ping(null)->assertUnauthorized();
        $this->ping('wfl_nope')->assertUnauthorized();
        $this->ping($user->createToken('other', ['something'])->plainTextToken)->assertForbidden();
    }

    public function test_old_uploaders_get_426(): void
    {
        config(['ledger.min_client_version' => '1.0.0']);
        $token = User::factory()->create()->issueIngestToken('pc');

        $this->ping($token, ['X-Client-Version' => '0.9.0'])->assertStatus(426)->assertJsonPath('status', 'upgrade_required');
        $this->ping($token, ['X-Client-Version' => '1.0.0'])->assertOk();
    }

    public function test_pings_share_the_per_ip_budget_with_ingest(): void
    {
        config(['ledger.ingest.per_ip_per_minute' => 2]);

        $this->ping('wfl_bad')->assertUnauthorized();
        $this->ping('wfl_bad')->assertUnauthorized();
        $this->ping('wfl_bad')->assertStatus(429);
    }
}
