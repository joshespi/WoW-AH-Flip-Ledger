{{-- A titled card: heading, optional description, then the content. --}}
@props(['title', 'description' => null, 'width' => 'max-w-xl'])

<div class="card p-5 sm:p-7">
    <section {{ $attributes->class($width) }}>
        <h3 class="text-base font-semibold tracking-tight text-gray-900 dark:text-gray-50">{{ $title }}</h3>
        @if ($description)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $description }}</p>
        @endif

        {{ $slot }}
    </section>
</div>
