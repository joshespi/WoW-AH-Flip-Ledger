<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared demo account, and an admin impersonating someone, can look but not change
 * anything. Everything that is not a read is refused, except logging out, ending the
 * impersonation, and Livewire's update endpoint (filters, polling and form state travel in
 * those POSTs); the components refuse those writes themselves, in one place
 * (Livewire\Concerns\ReportsErrors::attempt), which is what protects the data behind Livewire.
 */
class ReadOnlySession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->readOnly() && ! $request->isMethodSafe()
            && ! $request->routeIs('logout', 'impersonate.stop', 'game.switch') && ! $request->is('livewire-*/update')) {
            if ($request->expectsJson()) {
                abort(403, 'This session is read-only.');
            }

            return back(fallback: route('dashboard'))->with('read_only_blocked', true);
        }

        return $next($request);
    }
}
