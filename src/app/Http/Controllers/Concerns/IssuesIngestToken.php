<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

trait IssuesIngestToken
{
    /**
     * Renders the page directly instead of redirecting, so the plain-text token is shown once
     * and never written to the session store.
     *
     * @param  callable(string): View  $page  renders the caller's page with the new token
     */
    private function issueToken(Request $request, callable $page): View|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        try {
            $token = $request->user()->issueIngestToken($data['name']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return $page($token);
    }
}
