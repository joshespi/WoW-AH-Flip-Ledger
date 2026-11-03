{{-- A strip under the nav saying this session is not an ordinary one (the demo, or an admin viewing
     as someone), with the one button that leaves it. --}}
@props(['tone' => 'amber', 'action', 'button'])

<div @class([
    'text-sm',
    'bg-red-100 dark:bg-red-900/40 text-red-900 dark:text-red-200' => $tone === 'red',
    'bg-amber-100 dark:bg-amber-900/40 text-amber-900 dark:text-amber-200' => $tone !== 'red',
]) role="status">
    <div class="px-4 py-2 sm:px-6 lg:px-8 flex flex-wrap items-center justify-between gap-2">
        <span>{{ $slot }}</span>
        <form method="post" action="{{ $action }}">
            @csrf
            <button class="underline font-medium">{{ $button }}</button>
        </form>
    </div>
</div>
