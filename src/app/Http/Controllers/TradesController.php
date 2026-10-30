<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Support\Csv;
use App\Support\Money;
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
            fputcsv($out, ['time', 'item', 'qty', 'cost', 'note'], ',', '"', '');

            $rows = DB::table('trades')->ownedBy($user)->where('side', Trade::SIDE_BUY)
                ->orderBy('traded_at')->orderBy('id')->cursor();

            foreach ($rows as $t) {
                fputcsv($out, [
                    $user->local($t->traded_at)->format('Y-m-d\TH:iP'),
                    'i:'.(int) $t->item_id,
                    $t->qty,
                    Money::fmt($t->cost_copper),
                    Csv::safe((string) $t->note),
                ], ',', '"', '');
            }

            fclose($out);
        }, 'trades.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
