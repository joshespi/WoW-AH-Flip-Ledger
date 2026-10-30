<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-IP request budget for the ingest endpoint that runs before authentication, so floods of
 * bad or missing tokens are limited too. Route-level middleware can't do this: Laravel's
 * priority sorting runs Authenticate first whatever the route order, so this is registered as
 * global middleware (bootstrap/app.php) and only acts on the /api/v1/* paths.
 */
class LimitIngestByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/*')) {
            return $next($request);
        }

        $key = 'ingest-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, config('ledger.ingest.per_ip_per_minute'))) {
            return response()->json(
                ['status' => 'throttled', 'message' => 'Too many requests. Slow down.'],
                429,
                ['Retry-After' => RateLimiter::availableIn($key)],
            );
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
