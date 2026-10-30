<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_pages_get_our_themed_404(): void
    {
        $this->get('/no-such-page')->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('<title>Page not found · WoW Flip Ledger</title>', false)
            ->assertSee("localStorage.getItem('theme')", false)
            ->assertDontSee('laravel.com');
    }

    public function test_hitting_a_rate_limit_says_when_to_try_again(): void
    {
        config(['ledger.ingest.trades_import_per_user_per_hour' => 1]);
        $user = User::factory()->create();
        $post = fn () => $this->actingAs($user)->post('/trades/import', ['csv_text' => "time,item,qty,cost\n2026-10-12T10:51Z,i:1,1,1\n"]);

        $post();
        $post()->assertStatus(429)
            ->assertSee('Slow down a little')
            ->assertSeeText('Please try again in 60 minutes.')
            ->assertSee('limited per hour');
    }

    public function test_a_server_error_page_renders_without_a_user_or_session(): void
    {
        Route::get('/_boom', fn () => throw new RuntimeException('kaboom internals'));

        $this->get('/_boom')->assertStatus(500)
            ->assertSee('Something went wrong')
            ->assertDontSee('kaboom internals')
            ->assertDontSee('csrf-token', false);
    }

    public function test_api_errors_stay_json(): void
    {
        $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJsonStructure(['message']);
    }
}
