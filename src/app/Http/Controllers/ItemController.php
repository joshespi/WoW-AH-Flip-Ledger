<?php

namespace App\Http\Controllers;

use App\Services\ItemDetailService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemController extends Controller
{
    public function show(Request $request, ItemDetailService $details, int $item): View
    {
        $data = $details->forUser($request->user(), $item) ?? abort(404);

        $market = $data['market'];

        // Everything the chart needs, in copper and epoch seconds; the script converts.
        $chart = [
            'daily' => $data['daily'],
            'steps' => $data['steps'],
            'buys' => $data['buys'],
            'sales' => $data['sales'],
            'typical' => $market['typical'] ?? null,
            'hist60' => $market['hist60'] ?? null,
            'labels' => [
                'median' => __('Daily median floor'),
                'min' => __('Daily low'),
                'typical' => __('Typical (30d)'),
                'hist60' => __('60d average'),
                'avg' => __('My average cost'),
                'buys' => __('My buys'),
                'sales' => __('My sales (after cut)'),
            ],
        ];

        return view('items.show', ['d' => $data, 'chart' => $chart, 'user' => $request->user()]);
    }
}
