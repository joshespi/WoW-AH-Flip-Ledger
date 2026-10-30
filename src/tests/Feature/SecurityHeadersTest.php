<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function csp(string $url = '/'): string
    {
        return (string) $this->get($url)->headers->get('Content-Security-Policy');
    }

    public function test_every_kind_of_response_carries_the_headers(): void
    {
        $user = User::factory()->create();

        $responses = [
            $this->get('/'),
            $this->get('/login'),
            $this->get('/does-not-exist'),                 // error page
            $this->getJson('/api/v1/ping'),                // API, unauthenticated
            $this->actingAs($user)->get('/dashboard'),
            $this->get('/settings/export'),                // file download
        ];

        foreach ($responses as $r) {
            $r->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
            $this->assertStringContainsString("frame-ancestors 'none'", $r->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString('camera=()', $r->headers->get('Permissions-Policy'));
        }
    }

    public function test_csp_allows_only_self_and_a_nonce_for_scripts(): void
    {
        $csp = $this->csp();

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{20,}' 'unsafe-eval'/", $csp);
        $this->assertStringNotContainsString('http', $csp, 'no third-party origins');
        $this->assertDoesNotMatchRegularExpression("/style-src [^;]*'unsafe-inline'/", $csp, 'style elements need the nonce; only style attributes are open');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $csp);
    }

    public function test_the_nonce_in_the_header_matches_every_inline_and_framework_script(): void
    {
        $res = $this->get('/');
        preg_match("/'nonce-([^']+)'/", $res->headers->get('Content-Security-Policy'), $m);
        $nonce = $m[1];
        $html = $res->getContent();

        // Every <script> tag in the page carries it: the inline theme script, Vite's module, Livewire's.
        preg_match_all('/<script\b[^>]*>/', $html, $tags);
        $this->assertGreaterThanOrEqual(3, count($tags[0]));

        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag, $tag);
        }
    }

    public function test_the_nonce_is_new_for_every_request(): void
    {
        $this->assertNotSame($this->csp(), $this->csp());
    }

    public function test_error_pages_get_a_working_nonce_too(): void
    {
        $res = $this->get('/does-not-exist')->assertNotFound();
        preg_match("/'nonce-([^']+)'/", $res->headers->get('Content-Security-Policy'), $m);

        $this->assertStringContainsString('nonce="'.$m[1].'"', $res->getContent());
    }

    public function test_hsts_and_upgrade_only_over_https(): void
    {
        $plain = $this->get('/');
        $plain->assertHeaderMissing('Strict-Transport-Security');
        $this->assertStringNotContainsString('upgrade-insecure-requests', $plain->headers->get('Content-Security-Policy'));

        $secure = $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/');
        $secure->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->assertStringContainsString('upgrade-insecure-requests', $secure->headers->get('Content-Security-Policy'));
    }

    public function test_http_is_redirected_to_the_configured_https_site_when_forced(): void
    {
        config(['ledger.force_https' => true, 'app.url' => 'https://ledger.example.com']);

        $this->get('/dashboard?x=1')->assertStatus(301)->assertRedirect('https://ledger.example.com/dashboard?x=1');
        $this->post('/login', [])->assertStatus(308)->assertRedirect('https://ledger.example.com/login');
        $this->get('/up')->assertOk();
        $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/login')->assertOk();
    }

    public function test_the_redirect_ignores_the_request_host(): void
    {
        config(['ledger.force_https' => true, 'app.url' => 'https://ledger.example.com']);

        $this->get('http://evil.example.net/login')->assertRedirect('https://ledger.example.com/login');
    }

    public function test_no_redirect_when_off_or_when_the_site_is_not_https(): void
    {
        config(['ledger.force_https' => false, 'app.url' => 'https://ledger.example.com']);
        $this->get('/login')->assertOk();

        config(['ledger.force_https' => true, 'app.url' => 'http://localhost:8087']);
        $this->get('/login')->assertOk();
    }
}
