{{-- Light / system / dark. State lives in localStorage and on <html class="dark">; see themeToggle() in app.js. --}}
<div x-data="themeToggle" role="group" aria-label="{{ __('Theme') }}"
     {{ $attributes->merge(['class' => 'inline-flex rounded-md border border-gray-300 dark:border-gray-600 p-0.5 text-gray-500 dark:text-gray-400']) }}>
    @foreach (['light' => __('Light'), 'system' => __('System'), 'dark' => __('Dark')] as $value => $label)
        <button type="button" @click="set('{{ $value }}')" :aria-pressed="pref === '{{ $value }}'"
                :class="pref === '{{ $value }}' ? 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-100' : 'hover:text-gray-700 dark:hover:text-gray-200'"
                title="{{ $label }}" class="p-1.5 rounded">
            <span class="sr-only">{{ $label }}</span>
            @if ($value === 'light')
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4"/></svg>
            @elseif ($value === 'dark')
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
            @else
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path stroke-linecap="round" d="M8 20h8m-4-4v4"/></svg>
            @endif
        </button>
    @endforeach
</div>
