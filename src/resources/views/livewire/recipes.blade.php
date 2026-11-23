<div class="space-y-6">
    @if ($message)
        <x-success>{{ $message }}</x-success>
    @endif

    <x-section :title="$editing ? __('Edit recipe') : __('Add a recipe')" width="max-w-none"
               :description="__('A recipe you paid for. Its cost counts as a loss on what it makes; link your crafts to it (Trades, Record a craft) and the profit from selling them pays it back.')">
        <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
            <div class="lg:col-span-2">
                <x-input-label for="name" :value="__('Recipe')" />
                <input id="name" type="text" wire:model="name" maxlength="100" placeholder="{{ __('Recipe: Deviate Delight') }}" class="mt-1 block w-full field text-sm">
            </div>
            <div>
                <x-input-label for="item" :value="__('Makes')" />
                <input id="item" type="text" wire:model="item" autocomplete="off" spellcheck="false"
                       placeholder="{{ __('Name or i:ID') }}" class="mt-1 block w-full field text-sm">
            </div>
            <div>
                <x-input-label for="cost" :value="__('Cost')" />
                <input id="cost" type="text" wire:model="cost" placeholder="75s" class="mt-1 block w-full field text-sm">
            </div>
            <div class="lg:col-span-2">
                <x-input-label for="time" :value="__('Bought (:tz)', ['tz' => $user->timezone])" />
                <input id="time" type="datetime-local" wire:model="time" class="mt-1 block w-full field text-sm">
            </div>
            <div class="lg:col-span-3">
                <x-input-label for="note" :value="__('Note')" />
                <input id="note" type="text" wire:model="note" maxlength="255" class="mt-1 block w-full field text-sm">
            </div>
            <div class="sm:col-span-2 lg:col-span-6 flex items-center gap-3">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editing ? __('Save') : __('Add recipe') }}</x-primary-button>
                @if ($editing)
                    <button type="button" wire:click="cancel" class="link text-sm">{{ __('Cancel') }}</button>
                @endif
                <x-input-error :messages="$errors->get('form')" />
            </div>
        </form>
    </x-section>

    <div class="card p-4 sm:p-6">
        <h2 class="card-title">{{ __('Recipe ROI') }}</h2>

        @if ($rows === [])
            <p class="empty">{{ __('No recipes yet. Add one above.') }}</p>
        @else
            <x-table-scroll class="mt-4" :label="__('Recipes')">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Your recipes and what they have earned.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Recipe') }}</th>
                            <th scope="col">{{ __('Makes') }}</th>
                            <th scope="col">{{ __('Bought') }}</th>
                            <th scope="col" class="num">{{ __('Cost') }}</th>
                            <th scope="col" class="num" title="{{ __('Crafts linked to this recipe') }}">{{ __('Crafts') }}</th>
                            <th scope="col" class="num">{{ __('Made') }}</th>
                            <th scope="col" class="num">{{ __('Sold') }}</th>
                            <th scope="col" class="num" title="{{ __('Profit on the units sold, before the recipe cost.') }}">{{ __('Profit') }}</th>
                            <th scope="col" class="num" title="{{ __('Profit minus the recipe cost.') }}">{{ __('Net') }}</th>
                            <th scope="col" class="num">{{ __('ROI') }}</th>
                            <th scope="col">{{ __('Payback') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr wire:key="r{{ $r['id'] }}" @class(['bg-indigo-50 dark:bg-indigo-950/30' => $editing === $r['id']])>
                                <td>{{ $r['name'] }}</td>
                                <td><x-item-link :id="$r['item_id']" :name="$r['item_name']" /></td>
                                <td><x-time :at="$r['bought_at']" /></td>
                                <td class="num"><x-money :copper="$r['cost']" /></td>
                                <td class="num">{{ number_format($r['crafts']) }}</td>
                                <td class="num">{{ number_format($r['made']) }}</td>
                                <td class="num">{{ number_format($r['sold']) }}</td>
                                <td class="num"><x-money :copper="$r['profit']" pnl /></td>
                                <td class="num"><x-money :copper="$r['net']" pnl /></td>
                                <td class="num"><x-pct :value="$r['roi']" /></td>
                                <td>
                                    @if ($r['sold'] === 0)
                                        <span class="note">{{ __('Nothing sold yet') }}</span>
                                    @elseif ($r['to_go'] === 0)
                                        <x-chip type="{{ __('Paid back') }}" tone="green" />
                                    @else
                                        <span class="note">{{ __('To go:') }} <x-money :copper="$r['to_go']" /></span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-end">
                                    <button type="button" wire:click="edit({{ $r['id'] }})" class="link text-sm">{{ __('Edit') }}</button>
                                    <button type="button" wire:click="delete({{ $r['id'] }})" wire:confirm="{{ __('Delete this recipe? Its cost leaves your P/L and its crafts are unlinked.') }}"
                                            class="ms-3 link-danger text-sm">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-scroll>
            <p class="mt-3 note">{{ __('Profit counts units crafted with the recipe, sold oldest stock first, at your average cost. Crafts not linked to a recipe are not counted.') }}</p>
        @endif
    </div>
</div>
