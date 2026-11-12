<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Upload;
use App\Services\IngestService;
use App\Support\Bounds;
use App\Support\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/ingest, called by the companion uploader. Body is the raw TradeSkillMaster.lua,
 * gzipped or not (or a multipart `file`). Headers: X-File-Mtime (epoch seconds),
 * X-Client-Version, X-Wow-Account, X-Wow-Game (retail|forever|classic, default retail). Auth is a Sanctum token with the `ingest` ability.
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

        // Unknown games are refused, never filed as retail (the uploader drops a 422 until the file changes).
        $game = $request->header('X-Wow-Game') ?? Game::RETAIL;

        if (! Game::valid($game)) {
            return response()->json(['status' => Upload::STATUS_FAILED, 'message' => 'Unknown game. Update the uploader.'], 422);
        }

        $upload = $ingest->ingest(
            $request->user(),
            $payload,
            Upload::SOURCE_UPLOADER,
            Bounds::count($request->header('X-File-Mtime')),
            $request->header('X-Client-Version'),
            $request->header('X-Wow-Account'),
            $game,
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
