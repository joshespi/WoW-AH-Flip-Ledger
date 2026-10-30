<?php

namespace App\Http\Controllers;

use App\Services\DataExportService;
use App\Support\Utc;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DataExportController extends Controller
{
    public function __invoke(Request $request, DataExportService $export): BinaryFileResponse
    {
        $path = $export->build($request->user());

        return response()->download($path, 'wow-flip-ledger-export-'.Utc::day().'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
