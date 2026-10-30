<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AlertUnsubscribeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\PositionsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TradesController;
use App\Http\Controllers\TradesImportController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class);
Route::view('/about', 'pages.about')->name('about');
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/terms', 'pages.terms')->name('terms');
Route::get('/alerts/unsubscribe/{user}', [AlertUnsubscribeController::class, 'show'])->middleware('signed')->name('alerts.unsubscribe');
Route::post('/alerts/unsubscribe/{user}', [AlertUnsubscribeController::class, 'store'])->middleware(['signed', 'throttle:20,60'])->name('alerts.unsubscribe.store');
Route::post('/demo', DemoController::class)->middleware(['guest', 'throttle:20,60'])->name('demo');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/positions', [PositionsController::class, 'index'])->name('positions.index');

    foreach (['trades' => 'Trades', 'reconcile' => 'Reconcile', 'market' => 'Market', 'scan' => 'Scan', 'calculator' => 'Calculator'] as $page => $title) {
        Route::view("/{$page}", 'livewire-page', ['title' => $title, 'livewire' => $page])->name("{$page}.index");
    }

    Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('/setup/token', [SetupController::class, 'token'])->name('setup.token');
    Route::get('/download/SHA256SUMS', [DownloadController::class, 'sums'])->name('download.sums');
    Route::get('/download/addon', [DownloadController::class, 'addon'])->name('download.addon');
    Route::get('/download/{platform}', [DownloadController::class, 'build'])->name('download.build');
    Route::get('/items/{item}', [ItemController::class, 'show'])->whereNumber('item')->name('items.show');
    Route::get('/trades/export', [TradesController::class, 'export'])->middleware('not.impersonating')->name('trades.export');
    Route::get('/trades/import', [TradesImportController::class, 'create'])->name('trades.import.create');
    Route::get('/trades/import/template', [TradesImportController::class, 'template'])->name('trades.import.template');
    Route::post('/trades/import', [TradesImportController::class, 'store'])->middleware('throttle:trades-import')->name('trades.import.store');

    Route::get('/uploads', [UploadController::class, 'index'])->name('uploads.index');
    Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:manual-upload')->name('uploads.store');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/settings/export', DataExportController::class)->middleware(['not.impersonating', 'throttle:5,60'])->name('settings.export');
    Route::post('/settings/tokens', [SettingsController::class, 'storeToken'])->name('settings.tokens.store');
    Route::delete('/settings/tokens/{token}', [SettingsController::class, 'destroyToken'])->name('settings.tokens.destroy');
});

// Admin: 404 for everyone else (see EnsureAdmin). Read-only pages plus impersonation.
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'overview'])->name('overview');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/uploads', [AdminController::class, 'uploads'])->name('uploads');
    Route::get('/fetch', [AdminController::class, 'fetchRuns'])->name('fetch');
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->name('impersonate');
});

Route::middleware('auth')->group(function () {
    // Reachable while viewing as another account, who is not an admin.
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
