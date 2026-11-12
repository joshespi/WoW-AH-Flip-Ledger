<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

class WelcomeController extends Controller
{
    public function __invoke(): View
    {
        return view('welcome', ['demo' => User::demo()->exists()]);
    }
}
