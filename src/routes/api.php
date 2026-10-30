<?php

use App\Http\Controllers\Api\AddonFeedController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\PingController;
use Illuminate\Support\Facades\Route;

// Token-only API (no session auth). The per-IP limit is global middleware (LimitIngestByIp) so
// it runs before auth; the named `ingest` limiter runs after auth, per token. The version gate
// (426) goes last so a bad token is still a 401.
Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:ingest', 'verified', 'throttle:ingest', 'client.version'])->group(function () {
    Route::get('/ping', PingController::class)->name('api.ping');
    Route::post('/ingest', IngestController::class)->name('api.ingest');
});

// The addon feed polls on a timer, so it has its own per-token budget instead of eating ingest's.
Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:ingest', 'verified', 'throttle:addon-feed', 'client.version'])->group(function () {
    Route::get('/addon-feed', AddonFeedController::class)->name('api.addon-feed');
});
