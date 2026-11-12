<x-app-layout :title="__('Admin: uploads')" games="any">
    @include('admin.partials.tabs')

    <div class="card p-4 sm:p-6">
        <div class="card-head">
            <h3 class="card-title">{{ $failedOnly ? __('Failed uploads') : __('All uploads') }}</h3>
            <a class="link text-sm" href="{{ $failedOnly ? route('admin.uploads') : route('admin.uploads', ['failed' => 1]) }}">{{ $failedOnly ? __('Show all') : __('Only failures') }}</a>
        </div>
        @if ($uploads->isEmpty())
            <p class="empty">{{ __('No uploads.') }}</p>
        @else
            @include('admin.partials.uploads-table', ['uploads' => $uploads])
            <div class="mt-4">{{ $uploads->links() }}</div>
        @endif
    </div>
</x-app-layout>
