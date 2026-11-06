<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Services\DataExportService;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TradesController extends Controller
{
    /** The user's buys in the import format (`time,item,qty,cost,note`), oldest first. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        return response()->streamDownload(function () use ($user) {
            $out = fopen('php://output', 'w');
            Csv::put($out, DataExportService::TRADE_COLUMNS);

            $rows = DB::table('trades')->ownedBy($user)->inGame($user->activeGame())->where('side', Trade::SIDE_BUY)
                ->orderBy('traded_at')->orderBy('id')->cursor();

            foreach ($rows as $t) {
                Csv::put($out, DataExportService::tradeFields($user, $t));
            }

            fclose($out);
        }, 'trades.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
