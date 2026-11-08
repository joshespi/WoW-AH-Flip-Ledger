<?php

namespace App\Http\Middleware;

use App\Support\Utc;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes that a signed-in user was active today (UTC date only), for the public /stats counts.
 * Runs in terminate(), after the response, so no request waits on it and the API user is known.
 * On the web group and on the uploader's ingest route only (its ping and feed polls run unattended). The (user_id, day) key makes repeats a no-op; a cache guard
 * would cost the same query on the database cache store.
 */
class RecordActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if ($user && ! $user->readOnly()) {
            DB::table('user_activity_days')->insertOrIgnore(['user_id' => $user->id, 'day' => Utc::day()]);
        }
    }
}
