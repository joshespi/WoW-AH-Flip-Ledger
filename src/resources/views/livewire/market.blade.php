<div class="space-y-6">
    @if ($message)
        <x-success>{{ $message }}</x-success>
    @endif

    <x-section :title="__('Add to watchlist')" width="max-w-none"
               :description="__('Use an item name we already know, or i:ID. Find candidates on the Scan page.')">
        <form wire:submit="add" class="mt-4 flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="w-item" :value="__('Item')" />
                <input id="w-item" type="text" wire:model="item" autocomplete="off" spellcheck="false" placeholder="{{ __('Name or i:ID') }}"
                       class="mt-1 block w-64 field text-sm">
            </div>
            <div>
                <x-input-label for="w-group" :value="__('Group (optional)')" />
                <input id="w-group" type="text" maxlength="64" wire:model="group" class="mt-1 block w-48 field text-sm">
            </div>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="add">{{ __('Add') }}</x-primary-button>
        </form>
        <x-input-error class="mt-2" :messages="$errors->get('add')" />
    </x-section>

    <div class="card p-4 sm:p-6">
        <x-input-error class="mb-3" :messages="$errors->get('list')" />
        @if ($rows === [])
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Your watchlist is empty.') }}</p>
        @else
            <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Targets are per unit. A row is highlighted when the floor is at or under your buy target, or at or over your sell target.') }}</p>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Your watchlist.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Item') }}</th>
                            <th scope="col" class="num">{{ __('Floor') }}</th>
                            <th scope="col" class="num">{{ __('Buy at') }}</th>
                            <th scope="col" class="num">{{ __('Sell at') }}</th>
                            <th scope="col" class="num">{{ __('Recent') }}</th>
                            <th scope="col" class="num">{{ __('Typical') }}</th>
                            <th scope="col" class="num">{{ __('Sold / day') }}</th>
                            <th scope="col">{{ __('Signal') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    @php($last = false)
                    @foreach ($rows as $r)
                        @if ($r['group'] !== $last)
                            @php($last = $r['group'])
                            <tbody wire:key="g-{{ $r['id'] }}">
                                <tr><th colspan="9" scope="colgroup" class="text-start text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $r['group'] ?? __('Ungrouped') }}</th></tr>
                            </tbody>
                        @endif
                        <tbody wire:key="r-{{ $r['id'] }}">
                            <tr @class(['bg-green-50 dark:bg-green-900/20' => $r['at_buy'], 'bg-amber-50 dark:bg-amber-900/20' => $r['at_sell'] && ! $r['at_buy']])>
                                <td>
                                    <x-item-link :id="$r['item_id']" :name="$r['name']" />
                                    @if ($r['notes'])<div class="text-xs text-gray-500 dark:text-gray-400">{{ $r['notes'] }}</div>@endif
                                </td>
                                <td class="num"><x-money :copper="$r['floor']" /></td>
                                <td class="num"><x-money :copper="$r['buy']" /></td>
                                <td class="num"><x-money :copper="$r['sell']" /></td>
                                <td class="num">@if ($r['ready'])<x-money :copper="$r['recent']" />@else<x-collecting :days="$r['days']" />@endif</td>
                                <td class="num">@if ($r['ready'])<x-money :copper="$r['typical']" />@else<x-collecting :days="$r['days']" />@endif</td>
                                <td class="num">{{ $r['sold'] === null ? '—' : number_format($r['sold']) }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($r['at_buy'])<x-chip type="At buy target" tone="green" />@endif
                                    @if ($r['at_sell'])<x-chip type="At sell target" tone="amber" />@endif
                                    @if ($r['knife'])<x-chip type="Falling knife" title="{{ __('Cheap versus typical, and still dropping.') }}" tone="red" />@endif
                                </td>
                                <td class="whitespace-nowrap text-end text-sm">
                                    <button type="button" wire:click="move({{ $r['id'] }}, -1)" aria-label="{{ __('Move up') }}" class="px-1 text-gray-600 dark:text-gray-400">↑</button>
                                    <button type="button" wire:click="move({{ $r['id'] }}, 1)" aria-label="{{ __('Move down') }}" class="px-1 text-gray-600 dark:text-gray-400">↓</button>
                                    <button type="button" wire:click="edit({{ $r['id'] }})" class="ms-2 underline text-gray-600 dark:text-gray-400">{{ __('Edit') }}</button>
                                    <button type="button" wire:click="remove({{ $r['id'] }})" wire:confirm="{{ __('Remove from watchlist?') }}" class="ms-2 underline text-red-600 dark:text-red-400">{{ __('Remove') }}</button>
                                </td>
                            </tr>
                            @if ($editing === $r['id'])
                                <tr>
                                    <td colspan="9">
                                        <form wire:submit="save" class="flex flex-wrap items-end gap-3 py-2">
                                            <label class="text-xs">{{ __('Buy at') }}<input type="text" wire:model="buy" placeholder="12g 50s" class="mt-1 block w-32 field text-sm"></label>
                                            <label class="text-xs">{{ __('Sell at') }}<input type="text" wire:model="sell" placeholder="20g" class="mt-1 block w-32 field text-sm"></label>
                                            <label class="text-xs">{{ __('Group') }}<input type="text" maxlength="64" wire:model="editGroup" class="mt-1 block w-40 field text-sm"></label>
                                            <label class="text-xs grow">{{ __('Notes') }}<input type="text" maxlength="{{ \App\Support\Bounds::MAX_NOTE }}" wire:model="notes" class="mt-1 block w-full field text-sm"></label>
                                            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Save') }}</x-primary-button>
                                            <x-secondary-button type="button" wire:click="cancel">{{ __('Cancel') }}</x-secondary-button>
                                        </form>
                                        <x-input-error :messages="$errors->get('edit')" />
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
</div>
