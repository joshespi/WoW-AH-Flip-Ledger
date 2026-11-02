<div class="space-y-6">
    @if ($message)
        <x-success>{{ $message }}</x-success>
    @endif

    <x-section :title="__('Scan the market')" width="max-w-none"
               :description="__('Finds items that look cheap right now compared with their own recent prices, so you can decide what to buy and relist. Pick a ranking, narrow it with the filters if you like, then click an item to see its price history, or Watch it to keep an eye on it.')">
        <div class="mt-4 flex flex-wrap gap-1" role="group" aria-label="{{ __('Ranking') }}">
            @foreach ($modes as $key => $label)
                <button type="button" wire:click="$set('mode', '{{ $key }}')" aria-pressed="{{ $activeMode === $key ? 'true' : 'false' }}"
                        class="{{ $activeMode === $key ? 'btn-primary' : 'btn-secondary' }} !px-3 !py-1 text-xs">{{ $label }}</button>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            @switch($activeMode)
                @case('margin') {{ __('Typical price after the AH cut, minus the floor, per unit. Biggest first.') }} @break
                @case('volume') {{ __('Items at or under typical that sell the most units per day.') }} @break
                @case('winners') {{ __('Items you have already made money on (sales after the cut beat your buys) that are at or under typical now.') }} @break
                @default {{ __('Floor versus typical, cheapest first.') }}
            @endswitch
        </p>
        <div class="mt-4 flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="s-disc" :value="__('Min % under typical')" />
                <input id="s-disc" type="text" inputmode="decimal" wire:model.live.debounce.400ms="minDiscount" placeholder="e.g. 25"
                       class="mt-1 block w-32 field text-sm">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Floor at least this far below typical') }}</p>
            </div>
            <div>
                <x-input-label for="s-sold" :value="__('Min sold / day')" />
                <input id="s-sold" type="text" inputmode="numeric" wire:model.live.debounce.400ms="minSold" placeholder="e.g. 20"
                       class="mt-1 block w-32 field text-sm">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Skip slow sellers') }}</p>
            </div>
            <div>
                <x-input-label for="s-id" :value="__('Min item ID')" />
                <input id="s-id" type="text" inputmode="numeric" wire:model.live.debounce.400ms="minItemId"
                       class="mt-1 block w-32 field text-sm">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Higher IDs are newer items') }}</p>
            </div>
            <div>
                <x-input-label for="s-top" :value="__('Show top')" />
                <input id="s-top" type="text" inputmode="numeric" wire:model.live.debounce.400ms="top" placeholder="{{ config('ledger.market.scan_default_top') }}"
                       class="mt-1 block w-24 field text-sm">
            </div>
        </div>
        <x-input-error class="mt-2" :messages="$errors->get('add')" />
    </x-section>

    <div class="card p-4 sm:p-6 transition-opacity" wire:loading.class="opacity-60" wire:target="minDiscount,minSold,minItemId,top,mode,sortBy">
        @if ($rows === [])
            @if ($days < \App\Support\MarketStats::minDays())
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Still collecting price history.') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __(':have of :need days collected. Scan compares today\'s price with each item\'s own history, so it starts ranking items once they have :need days of data. Until then, the item pages still show the price chart so far.', ['have' => $days, 'need' => \App\Support\MarketStats::minDays()]) }}</p>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing matches. Try clearing the filters above, or pick a different ranking.') }}</p>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Items cheapest against their typical price.') }}</caption>
                    <thead>
                        <tr>
                            @foreach (['name' => __('Item'), 'floor' => __('Floor'), 'recent' => __('Recent'), 'typical' => __('Typical'), 'ratio' => __('Floor / typical'), 'margin' => __('Margin / unit'), 'roi' => __('ROI'), 'sold' => __('Sold / day')] as $col => $label)
                                <th scope="col" @class(['num' => $col !== 'name']) aria-sort="{{ $sort === $col ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                    <button type="button" wire:click="sortBy('{{ $col }}')">{{ $label }}{{ $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '' }}</button>
                                </th>
                            @endforeach
                            <th scope="col">{{ __(':n days', ['n' => \App\Services\ScanService::SPARK_DAYS]) }}</th>
                            <th scope="col">{{ __('Signal') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr wire:key="s{{ $r['item_id'] }}">
                                <td><x-item-link :id="$r['item_id']" :name="$r['name']" /> <span class="text-xs text-gray-400">#{{ $r['item_id'] }}</span></td>
                                <td class="num"><x-money :copper="$r['floor']" /></td>
                                <td class="num"><x-money :copper="$r['recent']" /></td>
                                <td class="num"><x-money :copper="$r['typical']" /></td>
                                <td class="num">{{ number_format($r['ratio'], 2) }}</td>
                                <td class="num"><x-money :copper="$r['margin']" pnl /></td>
                                <td class="num"><x-pct :value="$r['roi']" /></td>
                                <td class="num">{{ $r['sold'] === null ? '—' : number_format($r['sold']) }}</td>
                                <td><x-sparkline :values="$r['spark']" /></td>
                                <td>@if ($r['knife'])<x-chip type="Falling knife" title="{{ __('Cheap versus typical, and still dropping.') }}" tone="red" />@endif</td>
                                <td class="text-end text-sm whitespace-nowrap">
                                    @if ($r['watched'])
                                        <span class="text-gray-500 dark:text-gray-400">{{ __('Watching') }}</span>
                                    @else
                                        <button type="button" wire:click="add({{ $r['item_id'] }})" class="underline text-gray-600 dark:text-gray-400">{{ __('Watch') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
