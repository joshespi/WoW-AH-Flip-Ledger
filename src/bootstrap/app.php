<?php

use App\Http\Middleware\BlockWhenImpersonating;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\LimitIngestByIp;
use App\Http\Middleware\ReadOnlySession;
use App\Http\Middleware\RequireClientVersion;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Blizzard\BlizzardClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Skipped until Blizzard credentials exist. Every entry is onOneServer() (a stray second
        // scheduler can't double-fire it) and withoutOverlapping() with an expiry, so a crashed run
        // can't block the next one for the default 24 h.
        $configured = fn () => app(BlizzardClient::class)->configured();

        // Cheap when nothing changed (If-Modified-Since / Last-Modified dedupe). Hourly until
        // `prices:probe` shows Blizzard honors If-Modified-Since, then it can poll more often.
        $schedule->command('prices:fetch')->hourlyAt(5)->when($configured)->onOneServer()->withoutOverlapping(30);
        $schedule->command('prices:names')->everyFifteenMinutes()->when($configured)->onOneServer()->withoutOverlapping(10);
        // Fifteen minutes after the fetch; it does nothing unless that fetch brought new prices.
        $schedule->command('alerts:send')->hourlyAt(20)->onOneServer()->withoutOverlapping(30);
        // Tells the admins when fetching or backups go wrong (idle for what is not set up).
        $schedule->command('health:check')->everyThirtyMinutes()->onOneServer()->withoutOverlapping(20);
        $schedule->command('prices:prune')->dailyAt('03:30')->onOneServer()->withoutOverlapping(60);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // headers: is explicit because Laravel's default set includes X_FORWARDED_HOST, which
        // feeds $request->root() and so every absolute URL, including the password-reset and
        // email-verification links. A request that can name its own host could get a real
        // reset mail sent to a victim with a link to the attacker's domain. Scheme, port and
        // client IP are still taken from the proxy, so HTTPS detection and throttling work.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', null),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        // Pins the accepted Host to APP_URL. nginx answers any Host and passes it through, so
        // without this nothing constrains it. A no-op in local and testing.
        $middleware->trustHosts();

        // A mail client's one-click unsubscribe POSTs with no session; the link is signed instead.
        $middleware->validateCsrfTokens(except: ['alerts/unsubscribe/*']);

        // The demo account and impersonating admins can only read.
        $middleware->web(append: [ReadOnlySession::class]);

        // After TrustProxies/TrustHosts, so the scheme and host it judges are the real ones.
        $middleware->append(ForceHttps::class);
        $middleware->append(SecurityHeaders::class);

        // Per-IP limit on the ingest path that must run before authentication.
        $middleware->append(LimitIngestByIp::class);

        // Sanctum token ability checks and the uploader version gate for the API routes.
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'not.impersonating' => BlockWhenImpersonating::class,
            'abilities' => CheckAbilities::class,
            'client.version' => RequireClientVersion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
