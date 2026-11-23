{{-- Livewire "updating…" line, shown after a short delay while the named actions run. --}}
@props(['target'])

<p role="status" class="loading-status" wire:loading.delay.block wire:target="{{ $target }}">{{ $slot }}</p>
