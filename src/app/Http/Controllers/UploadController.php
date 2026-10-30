<?php

namespace App\Http\Controllers;

use App\Services\IngestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UploadController extends Controller
{
    public function index(Request $request): View
    {
        return view('uploads.index', [
            'uploads' => $request->user()->uploads()->newest()->limit(20)->get(),
        ]);
    }

    public function store(Request $request, IngestService $ingest): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv(config('ledger.ingest.max_request_bytes'), 1024)],
            // File.lastModified from the browser, in milliseconds.
            'file_mtime' => ['nullable', 'integer', 'min:0'],
        ]);

        $ms = $request->input('file_mtime');

        $upload = $ingest->ingest(
            $request->user(),
            $request->file('file')->get(),
            'manual',
            $ms === null ? null : intdiv((int) $ms, 1000),
        );

        if ($upload->failed()) {
            return back()->withErrors(['file' => $upload->error]);
        }

        return redirect()->route('uploads.index')->with('status', 'upload-ok');
    }
}
