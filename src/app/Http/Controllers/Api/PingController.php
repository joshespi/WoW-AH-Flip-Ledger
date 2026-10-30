<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/ping: lets the uploader check its token and version (setup, status) without
 * uploading anything. Same auth, ability and version gate as ingest.
 */
class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $last = $request->user()->uploads()->newest()->first();

        return response()->json([
            'status' => Upload::STATUS_OK,
            'message' => 'Token accepted.',
            'min_client_version' => config('ledger.min_client_version'),
            'server_time' => time(),
            'last_upload' => $last === null ? null : [
                'status' => $last->status,
                'received_at' => $last->received_at->toIso8601String(),
            ],
        ]);
    }
}
