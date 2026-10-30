<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function form(array $extra = []): array
    {
        return $extra + [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ];
    }

    public function test_the_browser_timezone_is_saved_at_signup(): void
    {
        $this->post('/register', $this->form(['timezone' => 'America/Denver']))->assertRedirect('/dashboard');

        $this->assertSame('America/Denver', User::firstWhere('email', 'new@example.com')->timezone);
    }

    public function test_a_missing_timezone_means_utc(): void
    {
        $this->post('/register', $this->form(['timezone' => '']))->assertRedirect('/dashboard');

        $this->assertSame('UTC', User::firstWhere('email', 'new@example.com')->timezone);
    }

    public function test_a_bogus_timezone_is_rejected(): void
    {
        $this->post('/register', $this->form(['timezone' => 'Mars/Phobos']))->assertSessionHasErrors('timezone');

        $this->assertSame(0, User::count());
    }

    public function test_the_register_page_carries_the_timezone_field(): void
    {
        $this->get('/register')->assertOk()->assertSee('name="timezone"', false);
    }

    public function test_signups_are_throttled_per_ip(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post('/register', $this->form(['email' => "u{$i}@example.com"]))->assertRedirect('/dashboard');
            auth()->logout();
        }

        $this->post('/register', $this->form(['email' => 'u11@example.com']))->assertStatus(429);
    }
}
