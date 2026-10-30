<?php

namespace Tests\Feature;

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

    public function test_footer_links_show_on_the_landing_and_auth_pages(): void
    {
        foreach (['/', '/login', '/register'] as $url) {
            $this->get($url)->assertOk()->assertSee(route('terms'), false);
        }
    }
}
