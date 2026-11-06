<x-app-layout :title="__('Admin: price fetches')" games="any">
    @include('admin.partials.tabs')
    @include('admin.partials.fetch-health')

    @php($me = auth()->user())
    <div class="card p-4 sm:p-6">
        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Every fetch attempt') }}</h3>
        @if ($runs->isEmpty())
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('No fetches yet. They start once Blizzard credentials are set.') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Price fetch runs, newest first.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Started') }}</th>
                            <th scope="col">{{ __('Region') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="num">{{ __('Seconds') }}</th>
                            <th scope="col" class="num">{{ __('Items') }}</th>
                            <th scope="col" class="num">{{ __('Auctions') }}</th>
                            <th scope="col">{{ __('Blizzard snapshot') }}</th>
                            <th scope="col">{{ __('Error') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $r)
                            <tr>
                                <td class="whitespace-nowrap">{{ $me->local($r->started_at)->format('Y-m-d H:i') }}</td>
                                <td>{{ strtoupper($r->region) }}</td>
                                <td><span class="{{ $r->status === 'failed' ? 'text-red-600 dark:text-red-400' : '' }}">{{ $r->status }}</span></td>
                                <td class="num">{{ $r->finished_at ? $r->finished_at->diffInSeconds($r->started_at, true) : '—' }}</td>
                                <td class="num">{{ number_format($r->items) }}</td>
                                <td class="num">{{ number_format($r->auctions) }}</td>
                                <td class="whitespace-nowrap">{{ $r->last_modified ? $me->local($r->last_modified)->format('Y-m-d H:i') : '—' }}</td>
                                <td class="text-gray-600 dark:text-gray-400 break-words max-w-md">{{ $r->error }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $runs->links() }}</div>
        @endif
    </div>
</x-app-layout>
