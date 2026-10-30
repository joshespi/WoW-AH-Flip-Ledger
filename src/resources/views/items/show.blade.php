<x-app-layout :title="$d['name']">
    <x-slot name="header">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-gray-50">{{ $d['name'] }} <span class="text-sm font-normal text-gray-400">#{{ $d['id'] }}</span></h2>
            <a href="{{ route('positions.index') }}" class="text-sm underline text-gray-600 dark:text-gray-400">{{ __('All positions') }}</a>
        </div>
    </x-slot>

    @php($m = $d['market'])
    @php($p = $d['position'])

    <div class="card p-4 sm:p-6">
        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-8 text-sm">
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Held') }}</dt><dd class="font-medium">{{ number_format($p['held']) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Avg cost') }}</dt><dd><x-money :copper="$p['avg'] === null ? null : (int) round($p['avg'])" /></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Realized') }}</dt><dd><x-money :copper="$p['realized']" pnl /></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Floor') }}</dt><dd><x-money :copper="$m['floor'] ?? null" /></dd></div>
            @if ($m && $m['ready'])
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Recent') }}</dt><dd><x-money :copper="$m['recent']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Typical') }}</dt><dd><x-money :copper="$m['typical']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('60d avg') }}</dt><dd><x-money :copper="$m['hist60']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Sold / day') }}</dt><dd>{{ $m['sold'] === null ? '—' : number_format($m['sold']) }}</dd></div>
            @elseif ($m)
                <div class="col-span-2 sm:col-span-4"><dt class="text-gray-500 dark:text-gray-400">{{ __('Estimates') }}</dt><dd><x-collecting :days="$m['days']" /></dd></div>
            @else
                <div class="col-span-2 sm:col-span-4"><dt class="text-gray-500 dark:text-gray-400">{{ __('Market') }}</dt><dd class="text-gray-500">{{ __('No price yet.') }}</dd></div>
            @endif
        </dl>
        @if ($p['own_qty'] > 0)
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __(':n sold units were not covered by your tracked buys (own stock) and are left out of realized profit.', ['n' => number_format($p['own_qty'])]) }}</p>
        @endif
    </div>

    <div class="card p-4 sm:p-6">
        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Price history') }}</h3>
        @if ($d['daily'] === [] && $d['buys'] === [] && $d['sales'] === [])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing to chart yet.') }}</p>
        @else
            {{-- Colour is inherited by the script for axis text, so it follows the theme. --}}
            <div class="mt-3 h-72 sm:h-96 text-gray-600 dark:text-gray-300" x-data="itemChart(@js($chart))" role="img"
                 aria-label="{{ __('Chart of daily floor prices with your buys and sales') }}">
                <canvas x-ref="canvas"></canvas>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Last :n days. Sales are plotted after the AH cut.', ['n' => \App\Services\ItemDetailService::CHART_DAYS]) }}</p>
        @endif
    </div>

    <div class="card p-4 sm:p-6">
        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Timeline') }}</h3>
        @if ($d['timeline'] === [])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('You have no buys, sales or listings for this item.') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Your buys, sales, expired and cancelled auctions, newest first.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('When') }}</th>
                            <th scope="col">{{ __('Event') }}</th>
                            <th scope="col" class="num">{{ __('Qty') }}</th>
                            <th scope="col" class="num">{{ __('Per unit') }}</th>
                            <th scope="col" class="num">{{ __('Profit') }}</th>
                            <th scope="col" class="num">{{ __('Held after') }}</th>
                            <th scope="col" class="num">{{ __('Avg after') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['timeline'] as $e)
                            <tr>
                                <td class="whitespace-nowrap">{{ $user->local($e['ts'])->format('Y-m-d H:i') }}</td>
                                <td>
                                    {{ match ($e['kind']) { 'buy' => __('Bought'), 'sale' => __('Sold'), 'adjust' => __('Adjusted'), 'expired' => __('Expired'), default => __('Cancelled') } }}
                                    @if (! $e['tracked'])<span class="text-xs text-gray-400" title="{{ __('Before your first tracked buy, so not in the ledger.') }}">({{ __('untracked') }})</span>@endif
                                </td>
                                <td class="num">{{ number_format($e['qty']) }}</td>
                                <td class="num"><x-money :copper="$e['unit']" /></td>
                                <td class="num"><x-money :copper="$e['pnl']" pnl /></td>
                                <td class="num">{{ isset($e['held']) ? number_format($e['held']) : '' }}</td>
                                <td class="num">@if (isset($e['held']))<x-money :copper="$e['avg'] === null ? null : (int) round($e['avg'])" />@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($d['timeline_total'] > count($d['timeline']))
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Showing the newest :n of :total events.', ['n' => count($d['timeline']), 'total' => number_format($d['timeline_total'])]) }}</p>
            @endif
        @endif
    </div>
</x-app-layout>
