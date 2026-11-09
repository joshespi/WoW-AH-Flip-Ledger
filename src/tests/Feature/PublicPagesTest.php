<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_public_and_carry_the_disclaimer(): void
    {
        foreach (['/about', '/privacy', '/terms'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('Not affiliated with Blizzard Entertainment or TradeSkillMaster')
                ->assertSee(route('privacy'), false)
                ->assertSee(route('terms'), false)
                ->assertSee(route('about'), false);
        }
    }

    public function test_pages_have_titles(): void
    {
        $this->get('/privacy')->assertSee('<title>Privacy · '.config('app.name').'</title>', false);
        $this->get('/terms')->assertSee('<title>Terms · '.config('app.name').'</title>', false);
        $this->get('/about')->assertSee('<title>About · '.config('app.name').'</title>', false);
    }

    public function test_privacy_states_what_is_and_is_not_stored(): void
    {
        $this->get('/privacy')->assertSee('Buyer and seller names are not stored')->assertSee('not kept')->assertSee('Download my data');
    }

    public function test_contact_address_appears_only_when_configured(): void
    {
        $this->get('/privacy')->assertDontSee('mailto:');

        config(['ledger.contact_email' => 'hello@example.com']);

        foreach (['/about', '/privacy', '/terms'] as $url) {
            $this->get($url)->assertSee('mailto:hello@example.com', false);
        }
    }

    public function test_footer_shows_version_and_issue_link_on_every_layout(): void
    {
        config(['ledger.version' => '1.2.3', 'ledger.issues_url' => 'https://example.com/issues']);

        foreach (['/', '/privacy', '/login'] as $url) {
            $this->get($url)->assertOk()->assertSee('Version 1.2.3')->assertSee('https://example.com/issues', false);
        }

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
            ->assertSee('Version 1.2.3')
            ->assertSee('https://example.com/issues', false)
            ->assertSee(route('privacy'), false);
    }

    public function test_empty_footer_env_values_fall_back_to_the_defaults(): void
    {
        $keys = ['LEDGER_VERSION', 'LEDGER_ISSUES_URL'];
        $saved = array_map(fn ($k) => [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)], array_combine($keys, $keys));

        try {
            foreach ($keys as $k) {
                $_ENV[$k] = $_SERVER[$k] = '';
                putenv("{$k}=");
            }

            $config = require config_path('ledger.php');
            $this->assertSame('dev', $config['version']);
            $this->assertStringStartsWith('https://', $config['issues_url']);
        } finally {
            foreach ($saved as $k => [$env, $server, $put]) {
                if ($env === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$k]);
                } else {
                    $_SERVER[$k] = $server;
                }
                putenv($put === false ? $k : "{$k}={$put}");
            }
        }
    }

    public function test_footer_links_show_on_the_landing_and_auth_pages(): void
    {
        foreach (['/', '/login', '/register'] as $url) {
            $this->get($url)->assertOk()->assertSee(route('terms'), false);
        }
    }
}
