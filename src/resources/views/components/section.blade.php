{{-- A titled card: heading, optional description, then the content. --}}
@props(['title', 'description' => null, 'width' => 'max-w-xl'])

<div class="card p-4 sm:p-6">
    <section {{ $attributes->class($width) }}>
        <h3 class="card-title">{{ $title }}</h3>
        @if ($description)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $description }}</p>
        @endif

        {{ $slot }}
    </section>
</div>
