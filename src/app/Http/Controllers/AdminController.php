<?php

namespace App\Http\Controllers;

use App\Services\AdminService;
use Illuminate\Http\Request;
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
}
