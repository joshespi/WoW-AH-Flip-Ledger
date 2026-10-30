<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After `auth`. Everyone else gets a 404, so the admin area does not advertise itself. An
 * admin who is currently impersonating is the impersonated user here, so they are locked out
 * until they stop.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 404);

        return $next($request);
    }
}
