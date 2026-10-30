<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After `auth`. For routes that hand data out (exports): an admin viewing as someone can look,
 * not carry it away. ReadOnlySession only stops writes, so these GET routes need this as well.
 */
class BlockWhenImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isBeingImpersonated(), 403, 'Not available while viewing as another user.');

        return $next($request);
    }
}
