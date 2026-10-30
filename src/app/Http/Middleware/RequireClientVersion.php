<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells uploaders older than ledger.min_client_version to update (HTTP 426). The uploader treats
 * this status as fatal, so it is the one place that decides it. A missing or malformed version
 * header passes (browsers, curl).
 */
class RequireClientVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $min = config('ledger.min_client_version');
        $version = (string) $request->header('X-Client-Version', '');

        if (preg_match('/^\d+(\.\d+){0,2}$/D', $version) && version_compare($version, $min, '<')) {
            return response()->json([
                'status' => 'upgrade_required',
                'message' => "This uploader is too old. Update to {$min} or newer.",
                'min_client_version' => $min,
            ], 426);
        }

        return $next($request);
    }
}
