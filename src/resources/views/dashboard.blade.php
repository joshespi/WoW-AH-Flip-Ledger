<x-app-layout :title="__('Dashboard')">
    @php
        $s = $portfolio->summary;
        $open = $portfolio->open();
    @endphp

    @if ($portfolio->isEmpty())
        <div class="card p-6 sm:p-8">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Your ledger is empty') }}</h3>
            <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">
                {{ __('Profit and loss need two things: your buys (the ledger takes them from you, because TSM\'s own buy log double-counts commodity lots) and your sales (taken from your TSM data).') }}
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-primary-button :href="route('trades.import.create')">{{ __('Import your trades') }}</x-primary-button>
                <x-secondary-button :href="route('setup.show')">{{ __('Set up automatic uploads') }}</x-secondary-button>
                <x-secondary-button :href="route('uploads.index')">{{ __('Upload TSM data') }}</x-secondary-button>
            </div>
        </div>
    @else
        @if ($portfolio->concentration)
            <div class="rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
                <span class="font-medium">{{ __('Concentrated:') }}</span>
                {{ __(':name is :pct% of your stock at cost.', ['name' => $portfolio->concentration['name'], 'pct' => number_format($portfolio->concentration['pct'], 0)]) }}
            </div>
        @endif

        <div class="grid grid-cols-1 xs:grid-cols-2 lg:grid-cols-5 gap-4">
            <x-stat-tile :label="__('Realized P/L')" :hint="__('Net of the :cut% AH cut', ['cut' => \App\Support\Money::AH_CUT * 100])">
                <x-money :copper="$s['realized']" pnl />
            </x-stat-tile>
            <x-stat-tile :label="__('Stock at cost')">
                <x-money :copper="$s['stock_cost']" />
            </x-stat-tile>
            <x-stat-tile :label="__('Stock at floor')"
                         :hint="$s['unpriced_items'] > 0 ? trans_choice(':n item has no price yet|:n items have no price yet', $s['unpriced_items'], ['n' => $s['unpriced_items']]) : __('Net of the AH cut')">
                <x-money :copper="$s['stock_at_floor']" />
            </x-stat-tile>
            <x-stat-tile :label="__('Unrealized P/L')" :hint="__('At the current floor')">
                <x-money :copper="$s['unrealized']" pnl />
            </x-stat-tile>
            <x-stat-tile :label="__('Own-stock sales')" :hint="__('Not counted in flip P/L')">
                <x-money :copper="$s['own_stock_sales']" />
            </x-stat-tile>
        </div>

        @php
            $rangeNames = [
                'day' => [__('Today'), __('Yesterday')],
                'week' => [__('This week'), __('Last week')],
                'month' => [__('This month'), __('Last month')],
                'quarter' => [__('This quarter'), __('Last quarter')],
                'all' => [__('All time'), ''],
            ];
        @endphp
        <div class="card p-4 sm:p-6 text-gray-600 dark:text-gray-300"
             x-data="realizedChart(@js(['ranges' => $ranges, 'labels' => ['bucket' => __('Realized P/L'), 'total' => __('Running total')]]))">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Realized over time') }}</h3>
            <div class="mt-3 grid grid-cols-2 sm:grid-cols-5 gap-2" role="group" aria-label="{{ __('Chart range') }}">
                @foreach ($rangeNames as $key => [$name, $prevName])
                    <button type="button" @click="select('{{ $key }}')" :aria-pressed="range === '{{ $key }}'"
                            :class="range === '{{ $key }}' ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-gray-200 dark:border-gray-700'"
                            class="rounded-md border px-3 py-2 text-start">
                        <span class="eyebrow block">{{ $name }}</span>
                        <span class="mt-1 block font-mono text-base font-semibold"><x-money :copper="$ranges[$key]['pnl']" pnl /></span>
                        @if ($ranges[$key]['prev'] !== null)
                            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $prevName }}: <x-money :copper="$ranges[$key]['prev']" pnl /></span>
                        @endif
                    </button>
                @endforeach
            </div>
            <div class="mt-4 h-72 sm:h-80" role="img" aria-label="{{ __('Chart of realized profit and loss over the selected range') }}">
                <canvas x-ref="canvas"></canvas>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Profit is booked when a sale is recorded, net of the AH cut, in your timezone. Weeks start on Monday.') }}</p>
        </div>

        <div class="card p-4 sm:p-6">
            <div class="flex items-baseline justify-between gap-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Positions') }}</h3>
                <a href="{{ route('positions.index') }}" class="text-sm underline text-gray-600 dark:text-gray-400">{{ __('All positions') }}</a>
            </div>

            @if ($open === [])
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing held right now.') }}</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="data-table">
                        <caption class="sr-only">{{ __('Largest open positions') }}</caption>
                        <thead>
                            <tr>
                                <th>{{ __('Item') }}</th>
                                <th class="num">{{ __('Held') }}</th>
                                <th class="num">{{ __('Avg cost') }}</th>
                                <th class="num">{{ __('Break-even') }}</th>
                                <th class="num">{{ __('Floor') }}</th>
                                <th class="num" title="{{ __('Held x floor, after the AH cut.') }}">{{ __('Value') }}</th>
                                <th class="num">{{ __('vs break-even') }}</th>
                                <th>{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice($open, 0, $rows) as $p)
                                <tr>
                                    <td class="text-gray-900 dark:text-gray-100"><x-item-link :id="$p->itemId" :name="$p->name" /></td>
                                    <td class="num">{{ number_format($p->held) }}</td>
                                    <td class="num"><x-money :copper="$p->avgCost" /></td>
                                    <td class="num"><x-money :copper="$p->breakEven" /></td>
                                    <td class="num"><x-money :copper="$p->floor" /></td>
                                    <td class="num"><x-money :copper="$p->valueAtFloor" /></td>
                                    <td class="num"><x-pct :value="$p->floorVsBreakEven" /></td>
                                    <td><x-position-status :position="$p" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($open) > $rows)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Showing the :n largest of :total.', ['n' => $rows, 'total' => count($open)]) }}</p>
                @endif
            @endif
        </div>

        <div class="card p-4 sm:p-6">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Recent activity') }}</h3>

            @if ($portfolio->recent === [])
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('No buys or sales yet.') }}</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="data-table">
                        <caption class="sr-only">{{ __('Your latest buys and sales, newest first') }}</caption>
                        <thead>
                            <tr>
                                <th>{{ __('When') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Item') }}</th>
                                <th class="num">{{ __('Qty') }}</th>
                                <th class="num">{{ __('Unit') }}</th>
                                <th class="num">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($portfolio->recent as $e)
                                <tr>
                                    <td class="tabular-nums text-gray-600 dark:text-gray-400">{{ $user->local($e['ts'])->format('M j H:i') }}</td>
                                    <td>
                                        @if ($e['type'] === 'sale')
                                            <span class="text-green-700 dark:text-green-400">{{ __('Sale') }}</span>
                                        @else
                                            <span class="text-gray-700 dark:text-gray-300">{{ __('Buy') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-gray-900 dark:text-gray-100"><x-item-link :id="$e['item_id']" :name="$e['name']" /></td>
                                    <td class="num">{{ number_format($e['qty']) }}</td>
                                    <td class="num"><x-money :copper="$e['unit']" /></td>
                                    <td class="num"><x-money :copper="$e['total']" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</x-app-layout>
