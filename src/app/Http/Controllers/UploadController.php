<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Services\IngestService;
use App\Services\UploadService;
use App\Support\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class UploadController extends Controller
{
    public function index(Request $request, UploadService $service): View
    {
        $user = $request->user();

        return view('uploads.index', [
            'uploads' => $user->uploads()->newest()->limit(20)->get(),
            'undoable' => $service->undoableIds($user),
        ]);
    }

    public function destroy(Request $request, UploadService $service, int $upload): RedirectResponse
    {
        try {
            $result = $service->undo($request->user(), $upload, $request->boolean('clear_inventory'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['undo' => $e->getMessage()]);
        }

        return redirect()->route('uploads.index')->with('status', 'upload-undone')->with('undone', $result);
    }

    public function store(Request $request, IngestService $ingest): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv(config('ledger.ingest.max_request_bytes'), 1024)],
            // File.lastModified from the browser, in milliseconds.
            'file_mtime' => ['nullable', 'integer', 'min:0'],
            'game' => ['nullable', Rule::in(Game::GAMES)],
            'game_confirmed' => ['nullable', 'boolean'],
        ]);

        $ms = $request->input('file_mtime');

        $upload = $ingest->ingest(
            $request->user(),
            $request->file('file')->get(),
            Upload::SOURCE_MANUAL,
            $ms === null ? null : intdiv((int) $ms, 1000),
            game: $request->input('game') ?? Game::RETAIL,
            checkRealms: ! $request->boolean('game_confirmed'),
        );

        if ($upload->wrongGameConfirmable !== null) {
            // A guess from the realms can be confirmed ("Upload anyway"); an addon file's own stamp cannot.
            return back()->withInput($request->only('game'))->withErrors(['game' => $upload->error])
                ->with('game_check', $upload->wrongGameConfirmable);
        }

        if ($upload->failed()) {
            return back()->withErrors(['file' => $upload->error]);
        }

        return redirect()->route('uploads.index')->with('status', 'upload-ok');
    }
}
