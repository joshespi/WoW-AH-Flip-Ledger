<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Upload;
use App\Services\IngestService;
use App\Support\Bounds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/ingest, called by the companion uploader. Body is the raw TradeSkillMaster.lua,
 * gzipped or not (or a multipart `file`). Headers: X-File-Mtime (epoch seconds),
 * X-Client-Version, X-Wow-Account. Auth is a Sanctum token with the `ingest` ability.
 */
class IngestController extends Controller
{
    public function __invoke(Request $request, IngestService $ingest): JsonResponse
    {
        if ((int) $request->header('Content-Length') > config('ledger.ingest.max_request_bytes')) {
            return response()->json(['status' => Upload::STATUS_FAILED, 'message' => 'Upload is too large.'], 413);
        }

        $payload = $request->hasFile('file') ? $request->file('file')->get() : $request->getContent();

        if ($payload === '' || $payload === false) {
            return response()->json(['status' => Upload::STATUS_FAILED, 'message' => 'The upload was empty.'], 422);
        }

        $upload = $ingest->ingest(
            $request->user(),
            $payload,
            'uploader',
            Bounds::count($request->header('X-File-Mtime')),
            $request->header('X-Client-Version'),
            $request->header('X-Wow-Account'),
        );

        return response()->json([
            'status' => $upload->status,
            'upload_id' => $upload->id,
            'message' => $upload->failed() ? $upload->error : 'Data received.',
            'counts' => $upload->counts,
            'min_client_version' => config('ledger.min_client_version'),
        ], ...match (true) {
            // The uploader retries a 503 but drops a 422 until the file changes.
            $upload->retryable => [503, ['Retry-After' => 60]],
            $upload->failed() => [422],
            default => [200],
        });
    }
}
