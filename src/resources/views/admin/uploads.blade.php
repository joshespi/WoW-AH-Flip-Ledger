<x-app-layout :title="__('Admin: uploads')" games="any">
    @include('admin.partials.tabs')

    <div class="card p-4 sm:p-6">
        <div class="flex items-baseline justify-between gap-4">
            <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $failedOnly ? __('Failed uploads') : __('All uploads') }}</h3>
            <a class="text-sm underline text-gray-600 dark:text-gray-400" href="{{ $failedOnly ? route('admin.uploads') : route('admin.uploads', ['failed' => 1]) }}">{{ $failedOnly ? __('Show all') : __('Only failures') }}</a>
        </div>
        @if ($uploads->isEmpty())
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('No uploads.') }}</p>
        @else
            @include('admin.partials.uploads-table', ['uploads' => $uploads])
            <div class="mt-4">{{ $uploads->links() }}</div>
        @endif
    </div>
</x-app-layout>
