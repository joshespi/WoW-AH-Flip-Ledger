{{-- Green confirmation box. The slot is the message; extra content goes after it. --}}
<div {{ $attributes->class(['p-4 rounded-md bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-sm font-medium text-green-800 dark:text-green-300']) }} role="status">
    {{ $slot }}
</div>
