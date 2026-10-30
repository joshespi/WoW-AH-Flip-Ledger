{{-- A published download: label, link with size, and its SHA-256. --}}
@props(['href', 'size', 'sha256'])

<div {{ $attributes }}>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $slot }}</span>
        <a href="{{ $href }}" class="underline text-gray-700 dark:text-gray-300">{{ __('Download') }} ({{ \Illuminate\Support\Number::fileSize($size, 1) }})</a>
    </div>
    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400 break-all">SHA-256 <code>{{ $sha256 }}</code></div>
</div>
