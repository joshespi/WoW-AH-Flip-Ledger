<x-app-layout :title="$d['name']" games="market">
    <x-slot name="heading">{{ $d['name'] }} <span class="text-sm font-normal text-gray-400 dark:text-gray-500">#{{ $d['id'] }}</span></x-slot>
    <x-slot name="actions"><a href="{{ route('positions.index') }}" class="link text-sm">{{ __('All positions') }}</a></x-slot>

    @php($m = $d['market'])
    @php($p = $d['position'])

    <div class="card p-4 sm:p-6">
        <dl class="grid grid-cols-1 xs:grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-8 text-sm">
            <div><dt class="dl-label">{{ __('Held') }}</dt><dd class="font-medium">{{ number_format($p['held']) }}</dd></div>
            <div><dt class="dl-label">{{ __('Avg cost') }}</dt><dd><x-money :copper="$p['avg'] === null ? null : (int) round($p['avg'])" /></dd></div>
            <div><dt class="dl-label">{{ __('Realized') }}</dt><dd><x-money :copper="$p['realized']" pnl /></dd></div>
            <div><dt class="dl-label">{{ __('Floor') }}</dt><dd><x-money :copper="$m['floor'] ?? null" /></dd></div>
            @if ($m && $m['ready'])
                <div><dt class="dl-label">{{ __('Recent') }}</dt><dd><x-money :copper="$m['recent']" /></dd></div>
                <div><dt class="dl-label">{{ __('Typical') }}</dt><dd><x-money :copper="$m['typical']" /></dd></div>
                <div><dt class="dl-label">{{ __('60d avg') }}</dt><dd><x-money :copper="$m['hist60']" /></dd></div>
                <div><dt class="dl-label">{{ __('Sold / day') }}</dt><dd><x-num :value="$m['sold']" /></dd></div>
            @elseif ($m)
                <div class="col-span-2 sm:col-span-4"><dt class="dl-label">{{ __('Estimates') }}</dt><dd><x-collecting :days="$m['days']" /></dd></div>
            @else
                <div class="col-span-2 sm:col-span-4"><dt class="dl-label">{{ __('Market') }}</dt><dd class="text-gray-500 dark:text-gray-400">{{ __('No price yet.') }}</dd></div>
            @endif
        </dl>
        @if ($p['own_qty'] > 0)
            <p class="mt-3 note">{{ __(':n sold units were not covered by your tracked buys (own stock) and are left out of realized profit.', ['n' => number_format($p['own_qty'])]) }}</p>
        @endif
    </div>

    <div class="card p-4 sm:p-6">
        <h3 class="card-title">{{ __('Price history') }}</h3>
        @if ($d['daily'] === [] && $d['buys'] === [] && $d['sales'] === [])
            <p class="empty">{{ __('Nothing to chart yet.') }}</p>
        @else
            {{-- Colour is inherited by the script for axis text, so it follows the theme. --}}
            <div x-data="itemChart(@js($chart))" class="text-gray-600 dark:text-gray-300">
                <div class="mt-3 flex gap-1" role="group" aria-label="{{ __('Chart range') }}">
                    <template x-for="r in ranges" :key="r">
                        <button type="button" @click="setRange(r)" :aria-pressed="range === r"
                                class="seg py-1 text-xs" x-text="r + 'd'"></button>
                    </template>
                </div>
                <div class="mt-3 h-80 sm:h-[28rem]" role="img"
                     aria-label="{{ __('Chart of daily floor prices with your buys and sales') }}">
                    <canvas x-ref="canvas"></canvas>
                </div>
            </div>
            <p class="mt-2 note">{{ __('Dot and triangle size follow quantity; hover for details. Sales are plotted after the AH cut. Click a legend entry to hide a line.') }}</p>
        @endif
    </div>

    <div class="card p-4 sm:p-6">
        <h3 class="card-title">{{ __('Timeline') }}</h3>
        @if ($d['timeline'] === [])
            <p class="empty">{{ __('You have no buys, sales or listings for this item.') }}</p>
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
                                <td><x-time :at="$e['ts']" /></td>
                                <td>
                                    {{ match ($e['kind']) { 'buy' => __('Bought'), 'sale' => __('Sold'), 'expired' => __('Expired'), 'cancelled' => __('Cancelled'), default => \App\Models\Trade::correctionLabel($e['kind']) } }}
                                    @if (! $e['tracked'])<span class="text-xs text-gray-400 dark:text-gray-500" title="{{ __('Before your first tracked buy, so not in the ledger.') }}">({{ __('untracked') }})</span>@endif
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
                <p class="mt-3 note">{{ __('Showing the newest :n of :total events.', ['n' => count($d['timeline']), 'total' => number_format($d['timeline_total'])]) }}</p>
            @endif
        @endif
    </div>

    <livewire:correct-holdings :item-id="$d['id']" :ledger-held="$p['held']" />
</x-app-layout>
