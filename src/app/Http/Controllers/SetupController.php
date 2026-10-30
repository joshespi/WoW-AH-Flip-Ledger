<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\IssuesIngestToken;
use App\Services\UploaderBuilds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SetupController extends Controller
{
    use IssuesIngestToken;

    public function show(Request $request, UploaderBuilds $builds): View
    {
        return $this->page($request, $builds);
    }

    public function token(Request $request, UploaderBuilds $builds): View|RedirectResponse
    {
        return $this->issueToken($request, fn (string $token) => $this->page($request, $builds, $token));
    }

    private function page(Request $request, UploaderBuilds $builds, ?string $token = null): View
    {
        return view('setup.show', [
            'current' => $builds->current(),
            'addon' => $builds->addon(),
            'guess' => UploaderBuilds::guess($request->userAgent()),
            'server' => $request->getSchemeAndHttpHost(),
            'token' => $token,
            'minVersion' => config('ledger.min_client_version'),
        ]);
    }
}
