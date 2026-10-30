<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends plain-HTTP requests to the https site named by APP_URL (never to the request's own
 * Host header). Off unless LEDGER_FORCE_HTTPS is set and APP_URL is https, because behind a
 * proxy that Laravel does not trust (TRUSTED_PROXIES) every request looks like HTTP and this
 * would loop. The health check is exempt so the container probe works over plain HTTP.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = parse_url((string) config('app.url'));

        if (config('ledger.force_https') && ($site['scheme'] ?? '') === 'https' && isset($site['host'])
            && ! $request->isSecure() && ! $request->is('up')) {
            $target = 'https://'.$site['host'].(isset($site['port']) ? ':'.$site['port'] : '').$request->getRequestUri();

            // 308 keeps the method and body for a POST that arrived over HTTP.
            return redirect()->to($target, $request->isMethodSafe() ? 301 : 308);
        }

        return $next($request);
    }
}
