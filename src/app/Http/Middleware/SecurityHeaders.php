<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every response, including error pages.
 *
 * The CSP allows only our own origin plus a per-request nonce for the one inline theme script
 * (Vite and Livewire pick the same nonce up through Vite::cspNonce()). `unsafe-eval` stays in
 * script-src because Alpine's standard build evaluates the expressions in our templates; the
 * CSP-safe build would forbid them. Inline style attributes (Alpine's x-cloak and show/hide
 * markup) are allowed by style-src-attr; style elements need the nonce. Nothing third-party
 * is loaded, so nothing third-party is allowed.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        if ($request->isSecure()) {
            $csp[] = 'upgrade-insecure-requests';
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $headers->set('Content-Security-Policy', implode('; ', $csp));
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        return $response;
    }
}
