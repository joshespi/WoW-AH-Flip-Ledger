<?php

namespace App\Http\Controllers;

use App\Services\TradesImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TradesImportController extends Controller
{
    /** Pasted text is flashed back on an error so it can be fixed, but not a whole 1 MB paste. */
    private const MAX_FLASH_BYTES = 64 * 1024;

    public function create(): View
    {
        return view('trades.import');
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print (TradesImportService::TEMPLATE."\n"),
            'trades-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function store(Request $request, TradesImportService $import): RedirectResponse
    {
        $request->validate([
            'csv' => ['nullable', 'file', 'max:'.intdiv(TradesImportService::MAX_BYTES, 1024)],
            'csv_text' => ['nullable', 'string', 'max:'.TradesImportService::MAX_BYTES],
        ]);

        $file = $request->file('csv');
        $content = $file ? (string) file_get_contents($file->getRealPath()) : (string) $request->input('csv_text');

        if (trim($content) === '') {
            return back()->withErrors(['csv' => __('Choose a CSV file or paste its contents.')]);
        }

        try {
            $result = $import->import($request->user(), $content, $request->user()->activeGame());
        } catch (InvalidArgumentException $e) {
            $keep = strlen((string) $request->input('csv_text')) <= self::MAX_FLASH_BYTES ? $request->only('csv_text') : [];

            return back()->withInput($keep)->withErrors(['csv' => $e->getMessage()]);
        }

        return redirect()->route('trades.import.create')->with('imported', $result);
    }
}
