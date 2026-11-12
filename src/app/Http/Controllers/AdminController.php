<?php

namespace App\Http\Controllers;

use App\Services\AdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function overview(AdminService $admin): View
    {
        return view('admin.overview', ['o' => $admin->overview()]);
    }

    public function users(Request $request, AdminService $admin): View
    {
        $search = (string) $request->query('q', '');

        return view('admin.users', ['users' => $admin->users(mb_substr($search, 0, 100)), 'search' => $search]);
    }

    public function uploads(Request $request, AdminService $admin): View
    {
        return view('admin.uploads', ['uploads' => $admin->uploads($request->boolean('failed')), 'failedOnly' => $request->boolean('failed')]);
    }

    public function fetchRuns(AdminService $admin): View
    {
        return view('admin.fetch', ['runs' => $admin->fetchRuns(), 'health' => $admin->fetchHealth()]);
    }

    // Same code path as the scheduled run; an unchanged snapshot costs one conditional request.
    // nginx waits just past this time limit (location = /admin/fetch).
    public function fetchNow(): RedirectResponse
    {
        set_time_limit(300);
        $code = Artisan::call('prices:fetch');
        $out = trim(Artisan::output());

        return redirect()->route('admin.fetch')->with($code === 0 ? 'fetch_ok' : 'fetch_error', $out);
    }
}
