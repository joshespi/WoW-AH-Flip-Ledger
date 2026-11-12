<div class="space-y-6">
    @if ($message)
        <x-success>{{ $message }}</x-success>
    @endif

    <x-section :title="$editing ? __('Edit buy') : __('Add a buy')" width="max-w-none"
               :description="__('Type the lot total, not the per-unit price: 2730.82 or 2,730g 82s.')">
        <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-7">
            <div class="relative lg:col-span-2">
                <x-input-label for="item" :value="__('Item')" />
                <input id="item" type="text" wire:model.live.debounce.250ms="item" autocomplete="off" spellcheck="false"
                       placeholder="{{ __('Name or i:ID') }}" class="mt-1 block w-full field text-sm">
                @if ($this->suggestions)
                    <ul class="absolute z-10 mt-1 w-full rounded-md border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-lg text-sm">
                        @foreach ($this->suggestions as $s)
                            <li wire:key="s{{ $s['id'] }}">
                                <button type="button" wire:click="pick({{ $s['id'] }})"
                                        class="block w-full px-3 py-2 text-start hover:bg-gray-100 dark:hover:bg-gray-700">{{ $s['name'] }} <span class="text-gray-400 dark:text-gray-500">#{{ $s['id'] }}</span></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <x-input-label for="qty" :value="__('Quantity')" />
                <input id="qty" type="text" inputmode="numeric" wire:model.live.debounce.250ms="qty"
                       class="mt-1 block w-full field text-sm">
            </div>
            <div>
                <x-input-label for="cost" :value="__('Total cost')" />
                <input id="cost" type="text" wire:model.live.debounce.250ms="cost" placeholder="2,730g 82s"
                       class="mt-1 block w-full field text-sm">
                <p class="mt-1 note">
                    @if ($this->unitCopper !== null)
                        {{ __('Per unit:') }} <x-money :copper="$this->unitCopper" />
                    @else
                        &nbsp;
                    @endif
                </p>
            </div>
            <div class="lg:col-span-2">
                <x-input-label for="time" :value="__('When (:tz)', ['tz' => $user->timezone])" />
                <input id="time" type="datetime-local" wire:model="time"
                       class="mt-1 block w-full field text-sm">
            </div>
            <div>
                <x-input-label for="note" :value="__('Note')" />
                <input id="note" type="text" maxlength="{{ \App\Support\Bounds::MAX_NOTE }}" wire:model="note"
                       class="mt-1 block w-full field text-sm">
            </div>

            <div class="sm:col-span-2 lg:col-span-7 flex items-center gap-3">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editing ? __('Save changes') : __('Add buy') }}</x-primary-button>
                @if ($editing)
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Cancel') }}</x-secondary-button>
                @endif
                <x-input-error :messages="$errors->get('form')" />
            </div>
        </form>
    </x-section>

    <x-section :title="__('Paste from chat')" width="max-w-none"
               :description="__('Paste the auction-house lines, for example: You won an auction for [Practically Pork]x5351 for 2,730g 82s. Other lines are ignored. They are stamped with the current time; edit a row afterwards if needed.')">
        <form wire:submit="addPasted" class="mt-4 space-y-3">
            <textarea wire:model="pasted" rows="4" spellcheck="false" aria-label="{{ __('Pasted chat lines') }}"
                      class="block w-full field font-mono text-xs"></textarea>
            <x-input-error :messages="$errors->get('pasted')" />
            <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="addPasted">{{ __('Add pasted buys') }}</x-secondary-button>
        </form>
    </x-section>

    <div class="card p-4 sm:p-6 transition-opacity" wire:loading.class="opacity-60" wire:target="search,delete,gotoPage,nextPage,previousPage">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Filter by item') }}" aria-label="{{ __('Filter by item') }}"
                   class="w-full sm:w-64 field text-sm">
            <div class="flex gap-4 text-sm">
                <a href="{{ route('reconcile.index') }}" class="link">{{ __('Reconcile with TSM') }}</a>
                <a href="{{ route('trades.import.create') }}" class="link">{{ __('Import CSV') }}</a>
                <a href="{{ route('trades.export') }}" class="link">{{ __('Export CSV') }}</a>
            </div>
        </div>

        @if ($rows->isEmpty())
            <p class="empty">{{ $search !== '' ? __('No buys match that filter.') : __('No buys yet. Add one above, paste from chat, or import a CSV.') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Your buys, newest first.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('When') }}</th>
                            <th scope="col">{{ __('Item') }}</th>
                            <th scope="col" class="num">{{ __('Qty') }}</th>
                            <th scope="col" class="num">{{ __('Total') }}</th>
                            <th scope="col" class="num">{{ __('Per unit') }}</th>
                            <th scope="col">{{ __('Note') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $t)
                            <tr wire:key="t{{ $t->id }}" @class(['bg-indigo-50 dark:bg-indigo-950/30' => $editing === $t->id])>
                                <td><x-time :at="$t->traded_at" /></td>
                                <td><x-item-link :id="$t->item_id" :name="$t->name" /></td>
                                @php($buy = $t->side === \App\Models\Trade::SIDE_BUY)
                                @if ($buy)
                                    <td class="num">{{ number_format($t->qty) }}</td>
                                    <td class="num"><x-money :copper="$t->cost_copper" /></td>
                                    <td class="num"><x-money :copper="$t->qty > 0 ? $t->cost_copper / $t->qty : null" /></td>
                                @else
                                    {{-- A correction removes stock at average cost; only a vendor sale has a price: the gold received. --}}
                                    @php($proceeds = \App\Models\Trade::proceeds($t->side, (int) $t->cost_copper))
                                    <td class="num">−{{ number_format($t->qty) }}</td>
                                    @if ($proceeds !== null)
                                        <td class="num"><x-money :copper="$proceeds" /></td>
                                    @endif
                                    <td @if ($proceeds === null) colspan="2" @endif class="text-end note">{{ \App\Models\Trade::correctionLabel($t->side) }}</td>
                                @endif
                                <td class="text-gray-600 dark:text-gray-400">{{ $t->note }}</td>
                                <td class="whitespace-nowrap text-end">
                                    @if ($buy)
                                        <button type="button" wire:click="edit({{ $t->id }})" class="link text-sm">{{ __('Edit') }}</button>
                                    @endif
                                    <button type="button" wire:click="delete({{ $t->id }})" wire:confirm="{{ ! $buy ? __('Undo this correction?') : ((int) $t->vendored > 0 ? __('Delete this buy? A vendor sale booked with it is deleted too.') : __('Delete this buy?')) }}"
                                            class="ms-3 link-danger text-sm">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
