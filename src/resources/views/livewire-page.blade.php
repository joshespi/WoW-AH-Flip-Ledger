{{-- A page that is one Livewire component: `title` for the tab and header, `livewire` is its name
     (not `component`: Blade reserves that variable inside components). --}}
<x-app-layout :title="__($title)">
    @livewire($livewire)
</x-app-layout>
