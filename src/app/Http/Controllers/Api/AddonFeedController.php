<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AddonFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/addon-feed: the token owner's positions, watchlist and active alerts, for the
 * uploader to write into the in-game addon. Same auth, ability and version gate as ingest.
 */
class AddonFeedController extends Controller
{
    public function __invoke(Request $request, AddonFeedService $feed): JsonResponse
    {
        return response()->json($feed->forUser($request->user()));
    }
}
