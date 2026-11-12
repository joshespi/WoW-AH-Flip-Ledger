<div id="correct" class="space-y-6 scroll-mt-6">
    @if ($message)
        <x-success>{{ $message }}</x-success>
    @endif

    @if ($ledgerHeld > 0 || $corrections->isNotEmpty())
        <x-section :title="__('Correct holdings')" width="max-w-none"
                   :description="__('Use this when the ledger shows stock you no longer have. To add stock, add a buy on the Trades page.')">
            <div class="mt-4 space-y-6">
            @if ($ledgerHeld > 0)
                <form wire:submit="save" class="space-y-4">
                    @if ($tsmStock !== null && $tsmStock < $ledgerHeld)
                        <p class="text-sm text-amber-700 dark:text-amber-400">
                            {{ __('Your latest TSM file shows :n fewer than the ledger. If they sold and the gold is still in your mail, collect it, /reload and upload again instead: correcting first would count those sales twice.', ['n' => number_format($ledgerHeld - $tsmStock)]) }}
                        </p>
                    @endif

                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <x-input-label for="c-held" :value="__('Set held to')" />
                            <input id="c-held" type="text" inputmode="numeric" wire:model="held" class="mt-1 block w-32 field text-sm num">
                            <p class="mt-1 note">
                                {{ __('Ledger: :n', ['n' => number_format($ledgerHeld)]) }}
                                @if ($tsmStock !== null)
                                    · {{ __('TSM stock: :n', ['n' => number_format($tsmStock)]) }}
                                @endif
                            </p>
                        </div>
                        <div class="grow sm:grow-0">
                            <x-input-label for="c-note" :value="__('Note (optional)')" />
                            <input id="c-note" type="text" maxlength="{{ \App\Support\Bounds::MAX_NOTE }}" wire:model="note" class="mt-1 block w-full sm:w-64 field text-sm">
                            <p class="mt-1 text-xs">&nbsp;</p>
                        </div>
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('What happened to the rest?') }}</legend>
                        <div class="mt-2 space-y-2 text-sm">
                            <label class="flex items-start gap-2">
                                <input type="radio" wire:model="reason" value="{{ \App\Models\Trade::SIDE_ADJUST }}" class="mt-0.5">
                                <span><span class="text-gray-900 dark:text-gray-100">{{ __('The ledger was wrong') }}</span>
                                    <span class="block note">{{ __('I never had them, or a sale was missed. Removes them at average cost with no profit or loss.') }}</span></span>
                            </label>
                            <label class="flex items-start gap-2">
                                <input type="radio" wire:model="reason" value="{{ \App\Models\Trade::SIDE_WRITEOFF }}" class="mt-0.5">
                                <span><span class="text-gray-900 dark:text-gray-100">{{ __('Written off') }}</span>
                                    <span class="block note">{{ __('Vendored, used or lost. Their average cost is booked as a realized loss.') }}</span></span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Correct') }}</x-primary-button>
                        <x-input-error :messages="$errors->get('correct')" />
                    </div>
                </form>
            @endif

            @if ($corrections->isNotEmpty())
                <div>
                    <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Your corrections') }}</h4>
                    <x-input-error class="mt-2" :messages="$errors->get('corrections')" />
                    <div class="mt-2 overflow-x-auto">
                        <table class="data-table">
                            <caption class="sr-only">{{ __('Your corrections for this item, newest first.') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('When') }}</th>
                                    <th scope="col">{{ __('Kind') }}</th>
                                    <th scope="col" class="num">{{ __('Removed') }}</th>
                                    <th scope="col">{{ __('Note') }}</th>
                                    <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($corrections as $c)
                                    <tr wire:key="c{{ $c->id }}">
                                        <td><x-time :at="$c->traded_at" /></td>
                                        <td>
                                            {{ \App\Models\Trade::correctionLabel($c->side) }}
                                            @if (($proceeds = \App\Models\Trade::proceeds($c->side, (int) $c->cost_copper)) !== null)
                                                <span class="ms-1 text-gray-600 dark:text-gray-400">{{ __('for') }} <x-money :copper="$proceeds" /></span>
                                            @endif
                                        </td>
                                        <td class="num">{{ number_format($c->qty) }}</td>
                                        <td class="text-gray-600 dark:text-gray-400">{{ $c->note }}</td>
                                        <td class="text-end">
                                            <button type="button" wire:click="undo({{ $c->id }})" wire:confirm="{{ __('Undo this correction?') }}"
                                                    wire:loading.attr="disabled" wire:target="undo"
                                                    class="link-danger text-sm">{{ __('Undo') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            </div>
        </x-section>
    @endif
</div>
