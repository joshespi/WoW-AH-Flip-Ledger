<div class="space-y-6">
    <x-section :title="__('Buy calculator')" width="max-w-none"
               :description="__('Enter what you would pay per item to see what to list it at. Prices include the :cut% AH cut.', ['cut' => \App\Support\Money::AH_CUT * 100])">
        <div class="mt-4 flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="c-cost" :value="__('Cost per item')" />
                <input id="c-cost" type="text" inputmode="text" autofocus wire:model.live.debounce.250ms="cost" placeholder="5g25s55c"
                       class="mt-1 block w-44 field text-sm">
            </div>
            <div>
                <x-input-label for="c-qty" :value="__('Quantity (optional)')" />
                <input id="c-qty" type="text" inputmode="numeric" wire:model.live.debounce.250ms="quantity"
                       class="mt-1 block w-36 field text-sm">
            </div>
        </div>
        @if ($error)
            <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
        @endif
        @if ($outlay !== null)
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Total outlay') }}: <x-money :copper="$outlay" /></p>
        @endif
    </x-section>

    @if ($rows !== [])
        <div class="card p-4 sm:p-6">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Listing price needed for each margin.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Margin') }}</th>
                            <th scope="col" class="num">{{ __('List at') }}</th>
                            <th scope="col" class="num">{{ __('Profit / item') }}</th>
                            @if ($outlay !== null)
                                <th scope="col" class="num">{{ __('Profit total') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr wire:key="m{{ $r['margin'] }}" @class(['font-semibold' => $r['target']])>
                                <td>
                                    @if ($r['margin'] == 0)
                                        {{ __('Break-even') }}
                                    @else
                                        {{ rtrim(rtrim(number_format($r['margin'], 2), '0'), '.') }}%
                                    @endif
                                    @if ($r['target'])<x-chip type="Your target" tone="indigo" />@endif
                                </td>
                                <td class="num"><x-money :copper="$r['sell']" /></td>
                                <td class="num"><x-money :copper="$r['profit']" pnl /></td>
                                @if ($outlay !== null)
                                    <td class="num"><x-money :copper="$r['stack']" pnl /></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
