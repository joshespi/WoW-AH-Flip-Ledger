<x-app-layout :title="__('Positions')">
    <x-slot name="header">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-gray-50">{{ __('Positions') }}</h2>

            @if ($closedCount > 0 || $showClosed)
                <a href="{{ $showClosed ? route('positions.index') : route('positions.index', ['closed' => 1]) }}"
                   class="text-sm underline text-gray-600 dark:text-gray-400">
                    {{ $showClosed ? __('Hide closed positions') : trans_choice('Show :n closed position|Show :n closed positions', $closedCount, ['n' => $closedCount]) }}
                </a>
            @endif
        </div>
    </x-slot>

    @php
        $cut = \App\Support\Money::AH_CUT;
        $below = config('ledger.positions.below_ratio');
        $above = config('ledger.positions.above_ratio');
        // [label, sort type, tooltip]; numbers in the tooltips come from the same config the service uses
        $cols = [
            ['Item', 'text', null],
            ['Held', 'num', null],
            ['Avg cost', 'num', __('Weighted average cost of what you still hold.')],
            ['Break-even', 'num', __('Average cost / :net: the sell price that covers the :cut% AH cut.', ['net' => 1 - $cut, 'cut' => $cut * 100])],
            ['Target sell', 'num', __('Average cost with your :margin% target margin and the AH cut (change it in Settings).', ['margin' => $user->target_margin_pct])],
            ['Floor', 'num', __('Lowest unit price on the auction house at the last Blizzard snapshot.')],
            ['vs break-even', 'num', __('Floor relative to break-even.')],
            ['Recent', 'num', __('Estimate: median floor over the last 24 hours.')],
            ['Typical', 'num', __('Estimate: median of daily median floors over 30 days.')],
            ['60d avg', 'num', __('Estimate: mean of daily median floors over 60 days.')],
            ['Floor / typical', 'num', __(':below or less is cheap, :above or more is rich.', ['below' => $below, 'above' => $above])],
            ['Sold / day', 'num', __('Estimate from hourly supply drops. Cancels and expiries also reduce supply, so treat it as a rough ceiling.')],
            ['Days to sell', 'num', __('Held / (sold per day x your market share, :share%).', ['share' => $user->market_share_pct])],
            ['Value at floor', 'num', __('Held x floor, after the AH cut.')],
            ['Unrealized', 'num', __('Value at floor minus cost.')],
            ['Realized', 'num', __('Profit or loss on what has sold, after the AH cut.')],
            ['Status', 'text', null],
        ];
    @endphp

    @if ($portfolio->isEmpty())
        <div class="card p-6 sm:p-8">
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No positions yet. Import your trades to start the ledger.') }}</p>
            <x-primary-button :href="route('trades.import.create')" class="mt-4">{{ __('Import your trades') }}</x-primary-button>
        </div>
    @else
        <div class="card p-4 sm:p-6">
            {{-- Scrolls both ways inside the card; the heading row and Item column stay in view. --}}
            <div class="max-h-[75vh] overflow-auto" x-data="sortableTable">
                <table class="data-table data-table-sticky">
                    <caption class="sr-only">{{ __('Your positions. Use the column buttons to sort.') }}</caption>
                    <thead>
                        <tr>
                            @foreach ($cols as $i => [$label, $type, $tip])
                                <th scope="col" class="{{ $type === 'num' ? 'num' : '' }}" :aria-sort="ariaSort({{ $i }})"
                                    @if ($tip) title="{{ $tip }}" @endif>
                                    <button type="button" @click="sort({{ $i }}, '{{ $type }}')"
                                            class="font-medium select-none rounded hover:text-gray-900 dark:hover:text-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">{{ __($label) }}<span aria-hidden="true" x-text="arrow({{ $i }})"></span></button>
                                </th>
                            @endforeach
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody x-ref="body">
                        @foreach ($rows as $p)
                            <tr>
                                <td data-v="{{ $p->name }}" class="text-gray-900 dark:text-gray-100">
                                    <x-item-link :id="$p->itemId" :name="$p->name" />
                                    @if ($p->unaccounted)
                                        <span class="block text-xs text-amber-700 dark:text-amber-400"
                                              title="{{ __('Your latest TSM file shows fewer of these than the ledger holds. TSM logs a sale when you collect the mail, so collect it, /reload and upload again.') }}">{{ __(':n more than your TSM stock', ['n' => number_format($p->unaccounted)]) }}</span>
                                    @endif
                                    @if ($p->closed())
                                        <span class="ms-1 text-xs text-gray-400 dark:text-gray-500">{{ __('closed') }}</span>
                                    @elseif ($p->floor === null)
                                        <span class="block text-xs italic text-gray-400 dark:text-gray-500">{{ __('no price yet') }}</span>
                                    @elseif (! $p->statsReady)
                                        <span class="block"><x-collecting :days="$p->daysOfData" /></span>
                                    @endif
                                </td>
                                <td data-v="{{ $p->held }}" class="num">{{ number_format($p->held) }}</td>
                                <td data-v="{{ $p->avgCost }}" class="num"><x-money :copper="$p->avgCost" /></td>
                                <td data-v="{{ $p->breakEven }}" class="num"><x-money :copper="$p->breakEven" /></td>
                                <td data-v="{{ $p->targetSell }}" class="num"><x-money :copper="$p->targetSell" /></td>
                                <td data-v="{{ $p->floor }}" class="num"><x-money :copper="$p->floor" /></td>
                                <td data-v="{{ $p->floorVsBreakEven }}" class="num"><x-pct :value="$p->floorVsBreakEven" /></td>
                                <td data-v="{{ $p->recent }}" class="num"><x-money :copper="$p->recent" /></td>
                                <td data-v="{{ $p->typical }}" class="num"><x-money :copper="$p->typical" /></td>
                                <td data-v="{{ $p->hist60 }}" class="num"><x-money :copper="$p->hist60" /></td>
                                <td data-v="{{ $p->floorVsTypical }}" class="num">{{ $p->floorVsTypical === null ? '—' : number_format($p->floorVsTypical, 2) }}</td>
                                <td data-v="{{ $p->soldPerDay }}" class="num">{{ $p->soldPerDay === null ? '—' : number_format($p->soldPerDay) }}</td>
                                <td data-v="{{ $p->daysToSell }}" class="num">{{ $p->daysToSell === null ? '—' : number_format($p->daysToSell, 1) }}</td>
                                <td data-v="{{ $p->valueAtFloor }}" class="num"><x-money :copper="$p->valueAtFloor" /></td>
                                <td data-v="{{ $p->unrealized }}" class="num"><x-money :copper="$p->unrealized" pnl /></td>
                                <td data-v="{{ $p->hasRealized() ? $p->realized : '' }}" class="num"><x-money :copper="$p->hasRealized() ? $p->realized : null" pnl /></td>
                                <td data-v="{{ $p->status }}"><x-position-status :position="$p" /></td>
                                <td class="whitespace-nowrap text-end">
                                    @if ($p->held > 0)
                                        <a href="{{ route('items.show', $p->itemId) }}#correct" class="text-sm underline text-gray-600 dark:text-gray-400"
                                           title="{{ __('Set what you hold, for stock the ledger counts but you no longer have.') }}">{{ __('Correct') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Floor, cost and profit are exact. Recent, typical, 60-day average, sold per day and what is built on them are our own estimates from hourly snapshots of the Blizzard auction house, not TSM\'s numbers, and appear once an item has :days days of history.', ['days' => \App\Support\MarketStats::minDays()]) }}
                {{ __('Click a column heading to sort.') }}
            </p>
        </div>
    @endif
</x-app-layout>
