<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\SeedsUserRows;
use Tests\TestCase;

/**
 * Walks every registered route so a new endpoint can't ship without an isolation check:
 *  - any route without auth must be on the public allow-list;
 *  - every authenticated route refuses guests;
 *  - every authenticated route that takes an id refuses another user's id, and fails the
 *    test if its parameter has no ownership fixture below (add one when adding the route).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsUserRows;

    /** Routes that are public on purpose. */
    private const PUBLIC_URIS = [
        '/',
        'up',
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'reset-password/{token}',
        'sanctum/csrf-cookie',
        'about',
        'privacy',
        'stats',
        'scoreboard',
        'terms',
        'demo',
        'alerts/unsubscribe/{user}',
    ];

    /**
     * @return list<LaravelRoute>
     */
    /** Authenticated routes whose parameter names shared, not owned, data (published builds). */
    private const SHARED_PARAM_URIS = ['download/{platform}'];

    private function routes(): array
    {
        // Framework-owned endpoints: Livewire's (update, uploads, assets; they carry their own
        // signed/checksummed payloads), the debug-bar style `_` routes and local-disk serving.
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $r) => ! str_starts_with($r->uri(), '_')
                && ! str_starts_with($r->uri(), 'livewire-')
                && $r->uri() !== 'storage/{path}',
        ));
    }

    private function requiresAuth(LaravelRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && (str_starts_with($middleware, 'auth') || str_contains($middleware, 'Middleware\\Authenticate'))) {
                return true;
            }
        }

        return false;
    }

    private function method(LaravelRoute $route): string
    {
        return collect($route->methods())->first(fn (string $m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
    }

    /**
     * @param  array<string, int|string>  $values
     */
    private function url(LaravelRoute $route, array $values = []): string
    {
        $uri = preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => (string) ($values[$m[1]] ?? 1), $route->uri());

        return '/'.ltrim($uri, '/');
    }

    public function test_every_public_route_is_on_the_allow_list(): void
    {
        $unexpected = [];

        foreach ($this->routes() as $route) {
            if (! $this->requiresAuth($route) && ! in_array($route->uri(), self::PUBLIC_URIS, true)) {
                $unexpected[] = $this->method($route).' '.$route->uri();
            }
        }

        $this->assertSame([], $unexpected, 'Public routes missing from PUBLIC_URIS (or missing auth middleware).');
    }

    public function test_every_authenticated_route_refuses_guests(): void
    {
        $leaks = [];

        foreach ($this->routes() as $route) {
            if (! $this->requiresAuth($route)) {
                continue;
            }

            $status = $this->json($this->method($route), $this->url($route))->getStatusCode();

            if ($status !== 401) {
                $leaks[] = "{$this->method($route)} {$route->uri()} => {$status}";
            }
        }

        $this->assertSame([], $leaks, 'Guests must get 401 on authenticated routes.');
    }

    public function test_authenticated_routes_with_ids_refuse_another_users_ids(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Parameter name => an id that belongs to Alice. Add a line for every new owned resource.
        $owned = [
            'token' => $alice->createToken('alice pc', ['ingest'])->accessToken->id,
            'id' => $alice->id,
            'upload' => $this->seedUpload($alice)->id,
            'hash' => sha1($alice->email),
            'item' => 987654,
            'user' => $alice->id,
        ];

        $checked = 0;

        foreach ($this->routes() as $route) {
            if (! $this->requiresAuth($route) || $route->parameterNames() === [] || in_array($route->uri(), self::SHARED_PARAM_URIS, true)) {
                continue;
            }

            foreach ($route->parameterNames() as $name) {
                $this->assertArrayHasKey($name, $owned, "Route {$route->uri()} has {param {$name}} with no ownership fixture in {$this->name()}.");
            }

            $status = $this->actingAs($bob)->json($this->method($route), $this->url($route, $owned))->getStatusCode();

            $this->assertContains($status, [403, 404], "{$this->method($route)} {$route->uri()} answered {$status} for another user's id.");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(2, $checked, 'The walker found no routes with ids; is it filtering too much?');
        $this->assertSame(1, $alice->tokens()->count(), "Bob's requests must not have revoked Alice's token.");
    }
}
