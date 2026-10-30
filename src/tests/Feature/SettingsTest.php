<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_renders_for_a_verified_user(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->assertSee('Uploader tokens');
    }

    public function test_timezone_and_region_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings', ['timezone' => 'America/Denver', 'region' => 'us'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'settings-updated');

        $this->assertSame('America/Denver', $user->fresh()->timezone);
    }

    public function test_invalid_timezone_and_region_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings', ['timezone' => 'Mars/Phobos', 'region' => 'us'])->assertSessionHasErrors('timezone');
        $this->actingAs($user)->patch('/settings', ['timezone' => 'UTC', 'region' => 'eu'])->assertSessionHasErrors('region');
        $this->actingAs($user)->patch('/settings', ['timezone' => '', 'region' => ''])->assertSessionHasErrors(['timezone', 'region']);

        $this->assertSame('UTC', $user->fresh()->timezone);
    }

    public function test_creating_a_token_shows_it_once_with_only_the_ingest_ability(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/tokens', ['name' => 'Gaming PC'])->assertOk();

        $token = $user->tokens()->sole();
        $this->assertSame('Gaming PC', $token->name);
        $this->assertSame(['ingest'], $token->abilities);

        $plain = $response->viewData('newToken');
        // Sanctum's format is "<id>|<prefix><secret>".
        $this->assertMatchesRegularExpression('/^\d+\|wfl_[A-Za-z0-9]{40}/', $plain);
        $response->assertSee($plain);

        // Not stored in plain text, and not shown again.
        $this->assertNotSame($plain, $token->token);
        $this->actingAs($user)->get('/settings')->assertOk()->assertDontSee($plain)->assertSee('Gaming PC')->assertSee('never used');
    }

    public function test_a_created_token_actually_works_against_the_api(): void
    {
        $user = User::factory()->create();
        $plain = $this->actingAs($user)->post('/settings/tokens', ['name' => 'PC'])->viewData('newToken');

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/ingest', [])->assertStatus(422);
    }

    public function test_token_names_are_validated_and_capped_at_ten(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/tokens', ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($user)->post('/settings/tokens', ['name' => str_repeat('x', 61)])->assertSessionHasErrors('name');

        foreach (range(1, 10) as $i) {
            $user->createToken("t{$i}", ['ingest']);
        }

        $this->actingAs($user)->post('/settings/tokens', ['name' => 'eleventh'])->assertSessionHasErrors('name');
        $this->assertSame(10, $user->tokens()->count());
    }

    public function test_revoking_a_token_stops_it_working(): void
    {
        $user = User::factory()->create();
        $new = $user->createToken('doomed', ['ingest']);

        $this->actingAs($user)->delete('/settings/tokens/'.$new->accessToken->id)->assertSessionHas('status', 'token-revoked');

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($new->plainTextToken)->postJson('/api/v1/ingest', [])->assertUnauthorized();
    }

    public function test_you_cannot_revoke_someone_elses_token(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $token = $alice->createToken('alice pc', ['ingest'])->accessToken;

        $this->actingAs($bob)->delete('/settings/tokens/'.$token->id)->assertNotFound();

        $this->assertSame(1, $alice->tokens()->count());
    }
}
