{{-- A page that is one Livewire component: `title` for the tab and header, `livewire` is its name
     (not `component`: Blade reserves that variable inside components), `games` as on <x-app-layout>. --}}
<x-app-layout :title="__($title)" :games="$games">
    @livewire($livewire)
</x-app-layout>
