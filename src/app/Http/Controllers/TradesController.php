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

        return Csv::download(
            'trades.csv',
            DataExportService::TRADE_COLUMNS,
            DB::table('trades')->ownedBy($user)->inGame($user->activeGame())->where('side', Trade::SIDE_BUY)
                ->orderBy('traded_at')->orderBy('id')->cursor(),
            fn (object $t) => DataExportService::tradeFields($user, $t),
        );
    }
}
