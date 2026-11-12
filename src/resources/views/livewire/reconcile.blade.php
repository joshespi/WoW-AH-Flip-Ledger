<div class="space-y-6">
    @if ($message)
        <x-success>{{ $message }}</x-success>
    @endif

    <div class="card p-4 sm:p-6">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Your addon or TSM logged these auction buys. TSM often double-counts commodity lots, so they are only suggestions. Accept the ones that are real, correct a quantity or cost first if needed, and ignore the rest. Buys you already recorded within :min minutes are not listed.', ['min' => intdiv(\App\Services\ReconcileService::WINDOW, 60)]) }}
        </p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Bought to sell to a vendor? Use Vendored: it adds the buy, then sells it to the vendor at the price you enter, and the profit lands in your realized P/L.') }}
        </p>
        @if ($vendored['units'] > 0)
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Vendored so far: :n units for', ['n' => number_format($vendored['units'])]) }} <x-money :copper="$vendored['proceeds']" />
            </p>
        @endif

        @if ($rows === [])
            <p class="empty">{{ __('Nothing to reconcile.') }}</p>
        @else
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                <span class="text-gray-600 dark:text-gray-400" aria-live="polite">{{ __(':n selected', ['n' => count($selected)]) }}</span>
                <button type="button" wire:click="selectAll" class="link">{{ __('Select all') }}</button>
                <span class="note">{{ __('Shift-click to select a range.') }}</span>
                <button type="button" wire:click="selectAll(true)" class="link">{{ __('Select all except likely doubles') }}</button>
                @if ($selected !== [])
                    <button type="button" wire:click="clearSelection" class="link">{{ __('Clear') }}</button>
                    @php($bulk = 'acceptSelected,ignoreSelected,vendorSelected')
                    <span class="flex gap-2 sm:ms-auto">
                        <x-primary-button type="button" wire:click="acceptSelected" wire:loading.attr="disabled" wire:target="{{ $bulk }}" wire:confirm="{{ __('Add the selected buys to your ledger?') }}">{{ __('Accept selected') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="vendorSelected" wire:loading.attr="disabled" wire:target="{{ $bulk }}" wire:confirm="{{ __('Add the selected buys and sell them to a vendor at each item\'s last vendor price? Items never vendored before are skipped.') }}" title="{{ __('Uses each item\'s last vendor price.') }}">{{ __('Vendored selected') }}</x-secondary-button>
                        <x-secondary-button type="button" wire:click="ignoreSelected" wire:loading.attr="disabled" wire:target="{{ $bulk }}" wire:confirm="{{ __('Ignore the selected suggestions? They will not come back.') }}">{{ __('Ignore selected') }}</x-secondary-button>
                    </span>
                @endif
            </div>
            <x-input-error :messages="$errors->get('bulk')" class="mt-2" />

            <div class="mt-4 overflow-x-auto" x-data="rangeSelect" @click="$event.target.matches('input[data-range]') && pick($event)">
                <table class="data-table">
                    <caption class="sr-only">{{ __('TSM buy suggestions waiting for a decision.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="w-8"><span class="sr-only">{{ __('Select') }}</span></th>
                            <th scope="col">{{ __('When') }}</th>
                            <th scope="col">{{ __('Item') }}</th>
                            <th scope="col" class="num">{{ __('Qty') }}</th>
                            <th scope="col" class="num">{{ __('Unit') }}</th>
                            <th scope="col" class="num">{{ __('Total') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr wire:key="c{{ $r['id'] }}">
                                <td><input type="checkbox" data-range wire:model.live="selected" value="{{ $r['id'] }}" aria-label="{{ __('Select this suggestion') }}"></td>
                                @if ($editing === $r['id'])
                                    <td><input type="datetime-local" wire:model="time" aria-label="{{ __('When') }}" class="field text-sm"></td>
                                    <td>{{ \App\Support\ItemName::display($r['name'], $r['item_id']) }}</td>
                                    <td class="num"><input type="text" inputmode="numeric" wire:model="qty" aria-label="{{ __('Quantity') }}" class="w-24 field text-sm text-end"></td>
                                    <td class="num"><x-money :copper="$r['qty'] > 0 ? (int) round($r['cost'] / $r['qty']) : null" /></td>
                                    <td class="num"><input type="text" wire:model="cost" aria-label="{{ __('Total cost') }}" class="w-40 field text-sm text-end"></td>
                                    <td class="whitespace-nowrap text-end">
                                        @if ($vendoring)
                                            <label class="block note">{{ __('Vendor paid, each') }}
                                                <input type="text" wire:model="vendorEach" placeholder="1g 20s" class="ms-2 w-32 field text-sm text-end">
                                            </label>
                                        @endif
                                        <button type="button" wire:click="saveEdited" class="link text-sm">{{ $vendoring ? __('Save and vendor') : __('Save and accept') }}</button>
                                        <button type="button" wire:click="cancel" class="ms-3 link text-sm">{{ __('Cancel') }}</button>
                                    </td>
                                @else
                                    <td><x-time :at="$r['at']" /></td>
                                    <td>
                                        <x-item-link :id="$r['item_id']" :name="$r['name']" />
                                        @if ($r['double'])
                                            <x-chip type="Likely double count" tone="amber" class="ms-2" title="{{ __('This quantity is exactly twice a buy you recorded at about the same time.') }}" />
                                        @endif
                                    </td>
                                    <td class="num">{{ number_format($r['qty']) }}</td>
                                    <td class="num"><x-money :copper="$r['qty'] > 0 ? (int) round($r['cost'] / $r['qty']) : null" /></td>
                                    <td class="num"><x-money :copper="$r['cost']" /></td>
                                    <td class="whitespace-nowrap text-end">
                                        <button type="button" wire:click="accept({{ $r['id'] }})" class="link text-sm">{{ __('Accept') }}</button>
                                        <button type="button" wire:click="edit({{ $r['id'] }}, true)" class="ms-3 link text-sm" title="{{ __('Add the buy and sell all of it to a vendor.') }}">{{ __('Vendored') }}</button>
                                        <button type="button" wire:click="edit({{ $r['id'] }})" class="ms-3 link text-sm">{{ __('Edit') }}</button>
                                        <button type="button" wire:click="ignore({{ $r['id'] }})" class="ms-3 link text-sm">{{ __('Ignore') }}</button>
                                    </td>
                                @endif
                            </tr>
                            @if ($errors->has('row'.$r['id']))
                                <tr wire:key="e{{ $r['id'] }}"><td colspan="7"><x-input-error :messages="$errors->get('row'.$r['id'])" /></td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (count($rows) >= \App\Services\ReconcileService::LIMIT)
                <p class="mt-3 note">{{ __('Showing the newest :n. Handle these to see more.', ['n' => \App\Services\ReconcileService::LIMIT]) }}</p>
            @endif
        @endif
    </div>
</div>
