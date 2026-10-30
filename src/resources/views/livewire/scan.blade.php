<div class="space-y-6">
    @if ($message)
        <x-success>{{ $message }}</x-success>
    @endif

    <x-section :title="__('Scan the market')" width="max-w-none"
               :description="__('Items trading under their own typical price. Only items with :min days of price history are scanned; the numbers are our estimates from Blizzard data, not TSM\'s.', ['min' => \App\Support\MarketStats::minDays()])">
        <div class="mt-4 flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="s-disc" :value="__('At least % under typical')" />
                <input id="s-disc" type="text" inputmode="decimal" wire:model.live.debounce.400ms="minDiscount" placeholder="25"
                       class="mt-1 block w-32 field text-sm">
            </div>
            <div>
                <x-input-label for="s-sold" :value="__('Min sold / day')" />
                <input id="s-sold" type="text" inputmode="numeric" wire:model.live.debounce.400ms="minSold"
                       class="mt-1 block w-32 field text-sm">
            </div>
            <div>
                <x-input-label for="s-id" :value="__('Min item ID')" />
                <input id="s-id" type="text" inputmode="numeric" wire:model.live.debounce.400ms="minItemId"
                       class="mt-1 block w-32 field text-sm">
            </div>
            <div>
                <x-input-label for="s-top" :value="__('Show top')" />
                <input id="s-top" type="text" inputmode="numeric" wire:model.live.debounce.400ms="top" placeholder="{{ config('ledger.market.scan_default_top') }}"
                       class="mt-1 block w-24 field text-sm">
            </div>
        </div>
        <x-input-error class="mt-2" :messages="$errors->get('add')" />
    </x-section>

    <div class="card p-4 sm:p-6">
        @if ($rows === [])
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing matches. Items appear here once they have enough price history.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Items cheapest against their typical price.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Item') }}</th>
                            <th scope="col" class="num">{{ __('Floor') }}</th>
                            <th scope="col" class="num">{{ __('Recent') }}</th>
                            <th scope="col" class="num">{{ __('Typical') }}</th>
                            <th scope="col" class="num">{{ __('Floor / typical') }}</th>
                            <th scope="col" class="num">{{ __('Sold / day') }}</th>
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
                                <td class="num">{{ $r['sold'] === null ? '—' : number_format($r['sold']) }}</td>
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
