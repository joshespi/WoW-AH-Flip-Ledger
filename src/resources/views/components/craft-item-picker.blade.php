@props(['picker', 'id', 'model', 'label', 'row' => null, 'vendor' => null])

<div {{ $attributes->class('min-w-0') }}>
    <div class="relative" x-data="{ open: false }"
         @focusout="if (! $el.contains($event.relatedTarget)) open = false"
         @keydown.escape.stop="if (open) { $refs.item.focus(); open = false }">
        <x-input-label :for="$id" :value="$label" />
        <input id="{{ $id }}" type="text" wire:model.live.debounce.250ms="{{ $model }}" x-ref="item"
               autocomplete="off" spellcheck="false" aria-describedby="{{ $id }}-hint"
               @focus="open = true" @input="open = true" @click="open = true"
               @keydown.down.prevent="open = true; $nextTick(() => $refs.choices?.querySelector('button')?.focus())"
               placeholder="{{ __('Name or i:ID') }}" class="mt-1 block w-full field text-sm">

        @if ($picker['choices'])
            <ul x-cloak x-show="open" x-ref="choices" aria-label="{{ __('Suggested items') }}"
                class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-[var(--border-control)] bg-[var(--surface)] shadow-lg text-sm">
                @foreach ($picker['choices'] as $itemId => $name)
                    <li wire:key="{{ $id }}-choice-{{ $itemId }}">
                        <button type="button" wire:click="pickCraftItem({{ $itemId }}, {{ $row ?? 'null' }})"
                                @click="$refs.item.focus(); open = false"
                                class="flex w-full flex-wrap justify-between gap-x-3 gap-y-1 px-3 py-2 text-start hover:bg-[var(--surface-elevated)] focus-visible:bg-[var(--surface-elevated)]">
                            <span>{{ $name }} <span class="text-xs text-[var(--text-muted)]">#{{ $itemId }}</span></span>
                            @if (isset($picker['stock'][$itemId]))
                                <span class="note">{{ __(':qty available', ['qty' => number_format($picker['stock'][$itemId])]) }}</span>
                            @elseif (isset($picker['prices'][$itemId]))
                                <span class="note">{{ __('Last paid') }} <x-money :copper="$picker['prices'][$itemId]" /> {{ __('each') }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @php($selected = $picker['selected'])
    <p id="{{ $id }}-hint" class="mt-1 note" aria-live="polite">
        @if ($selected !== null)
            {{ $selected['name'] }}
            @if ($vendor === false)
                · {{ __(':qty available at craft time', ['qty' => number_format($selected['stock'])]) }}
            @endif
        @elseif ($vendor === true)
            {{ __('Pick a previous vendor ingredient to reuse its price, or enter an item.') }}
        @elseif ($vendor === false)
            {{ __('Focus the field for stock suggestions, or search by name or item ID.') }}
        @else
            {{ __('Search for the finished item or enter its item ID.') }}
        @endif
    </p>
</div>
