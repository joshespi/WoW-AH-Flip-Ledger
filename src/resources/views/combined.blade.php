<x-app-layout :title="__('Dashboard')" games="any">
    @php
        $games = $user->enabledGames();
        $summary = $data['summary'];
        $compare = $data['compare'];
        $ranges = $data['ranges'];
        $names = \App\Support\Game::labels($games) + ['total' => __('Total')];
        $styles = array_combine($games, array_map(\App\Support\Game::chart(...), $games));
        $empty = collect($data['portfolios'])->every(fn ($p) => $p->isEmpty());
    @endphp

    @if ($empty)
        <div class="card p-6 sm:p-8">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('None of your ledgers has data yet') }}</h3>
            <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('Switch to one of your games in the sidebar to add buys, then come back here to see them together.') }}</p>
        </div>
    @else
        {{-- One card per game and the total: the split. --}}
        <div @class(['grid grid-cols-1 gap-4', 'lg:grid-cols-4' => count($games) > 2, 'lg:grid-cols-3' => count($games) <= 2])>
            @foreach ([...$games, 'total'] as $key)
                <div class="card p-4" @if ($key !== 'total') data-game="{{ $key }}" @endif>
                    <div class="flex items-center justify-between">
                        <p class="eyebrow">{{ $names[$key] }}</p>
                        @if ($key !== 'total')<x-game-badge :game="$key" />@endif
                    </div>
                    <p class="mt-2 font-mono text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-50"><x-money :copper="$summary[$key]['realized']" pnl /></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Realized P/L, net of the AH cut') }}</p>
                    <dl class="mt-3 space-y-1 text-sm">
                        <div class="flex flex-wrap justify-between gap-x-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('Stock at cost') }}</dt><dd class="font-mono text-end"><x-money :copper="$summary[$key]['stock_cost']" /></dd></div>
                        <div class="flex flex-wrap justify-between gap-x-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('Stock at floor') }}</dt><dd class="font-mono text-end"><x-money :copper="$summary[$key]['stock_at_floor']" /></dd></div>
                        <div class="flex flex-wrap justify-between gap-x-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('Unrealized P/L') }}</dt><dd class="font-mono text-end"><x-money :copper="$summary[$key]['unrealized']" pnl /></dd></div>
                    </dl>
                </div>
            @endforeach
        </div>
        <p class="-mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Only Retail has a price feed, so floor value and unrealized P/L are blank for the other games, and the Total for those two rows counts Retail only.') }}</p>

        <div class="card p-4 sm:p-6 text-gray-600 dark:text-gray-300"
             x-data="combinedChart(@js(['ranges' => $ranges, 'labels' => $names, 'games' => $games, 'styles' => $styles]))">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Realized over time, by game') }}</h3>
            <div class="mt-3 grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5 gap-2" role="group" aria-label="{{ __('Chart range') }}">
                @foreach (\App\Services\RealizedRangesService::labels() as $key => [$name])
                    <button type="button" @click="select('{{ $key }}')" :aria-pressed="range === '{{ $key }}'"
                            :class="range === '{{ $key }}' ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-gray-200 dark:border-gray-700'"
                            class="rounded-md border px-3 py-2 text-start">
                        <span class="eyebrow block">{{ $name }}</span>
                        <span class="mt-1 block font-mono text-base font-semibold"><x-money :copper="$ranges[$key]['pnl']['total']" pnl /></span>
                        @foreach ($games as $g)
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $names[$g] }} <x-money :copper="$ranges[$key]['pnl'][$g]" pnl /></span>
                        @endforeach
                    </button>
                @endforeach
            </div>
            <div class="mt-4 h-72 sm:h-80" role="img" aria-label="{{ __('Chart of running realized profit and loss for each game and in total') }}">
                <canvas x-ref="canvas"></canvas>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Retail is the solid gold line, Forever the dashed teal line, Classic the dotted violet line, the total the thick grey line. Running totals, in your timezone.') }}</p>
        </div>

        <div class="card p-4 sm:p-6">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Games side by side') }}</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Your games side by side') }}</caption>
                    <thead>
                        <tr><th scope="col">{{ __('Measure') }}</th>@foreach ($games as $g)<th scope="col" class="num">{{ $names[$g] }}</th>@endforeach</tr>
                    </thead>
                    <tbody>
                        <tr><td>{{ __('Realized P/L') }}</td>@foreach ($games as $g)<td class="num"><x-money :copper="$summary[$g]['realized']" pnl /></td>@endforeach</tr>
                        <tr><td>{{ __('Open positions') }}</td>@foreach ($games as $g)<td class="num">{{ number_format($compare[$g]['open']) }}</td>@endforeach</tr>
                        <tr><td>{{ __('Closed positions') }}</td>@foreach ($games as $g)<td class="num">{{ number_format($compare[$g]['closed']) }}</td>@endforeach</tr>
                        <tr><td>{{ __('Units sold') }}</td>@foreach ($games as $g)<td class="num">{{ number_format($compare[$g]['sold']) }}</td>@endforeach</tr>
                        <tr><td title="{{ __('Units sold out of units sold plus units still held.') }}">{{ __('Sell-through') }}</td>@foreach ($games as $g)<td class="num">{{ $compare[$g]['sell_through'] === null ? '—' : number_format($compare[$g]['sell_through'] * 100, 0).'%' }}</td>@endforeach</tr>
                        <tr><td title="{{ __('Sales that booked profit or loss, per day, over the last 30 days.') }}">{{ __('Flips per day (30d)') }}</td>@foreach ($games as $g)<td class="num">{{ number_format($compare[$g]['flips_per_day'], 1) }}</td>@endforeach</tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card p-4 sm:p-6">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Open positions, all games') }}</h3>
            @if ($data['rows'] === [])
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing held right now.') }}</p>
            @else
                @php
                    $cols = [[__('Game'), 'text'], [__('Item'), 'text'], [__('Held'), 'num'], [__('Avg cost'), 'num'], [__('Break-even'), 'num'], [__('Floor'), 'num'], [__('Value'), 'num'], [__('Status'), 'text']];
                @endphp
                <div class="mt-3 overflow-x-auto" x-data="sortableTable">
                    <table class="data-table">
                        <caption class="sr-only">{{ __('Open positions from all your games. Use the column buttons to sort.') }}</caption>
                        <thead>
                            <tr>
                                <x-sort-headers :cols="$cols" />
                            </tr>
                        </thead>
                        <tbody x-ref="body">
                            @foreach (array_slice($data['rows'], 0, $rows) as ['game' => $g, 'row' => $p])
                                <tr>
                                    <td data-v="{{ $g }}"><x-game-badge :game="$g" /></td>
                                    {{-- The item page follows the single-game switch, so only Retail rows link to it. --}}
                                    <td data-v="{{ $p->name }}" class="text-gray-900 dark:text-gray-100">
                                        @if (\App\Support\Game::hasMarket($g))<x-item-link :id="$p->itemId" :name="$p->name" />@else{{ $p->name }}@endif
                                    </td>
                                    <td data-v="{{ $p->held }}" class="num">{{ number_format($p->held) }}</td>
                                    <td data-v="{{ $p->avgCost }}" class="num"><x-money :copper="$p->avgCost" /></td>
                                    <td data-v="{{ $p->breakEven }}" class="num"><x-money :copper="$p->breakEven" /></td>
                                    <td data-v="{{ $p->floor }}" class="num"><x-money :copper="$p->floor" /></td>
                                    <td data-v="{{ $p->valueAtFloor }}" class="num"><x-money :copper="$p->valueAtFloor" /></td>
                                    <td data-v="{{ $p->status }}"><x-position-status :position="$p" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($data['rows']) > $rows)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Showing the :n largest of :total.', ['n' => $rows, 'total' => count($data['rows'])]) }}</p>
                @endif
            @endif
        </div>
    @endif
</x-app-layout>
