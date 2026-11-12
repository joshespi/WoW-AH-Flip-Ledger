<x-app-layout :title="__('Admin: price fetches')" games="any">
    @include('admin.partials.tabs')
    @include('admin.partials.fetch-health')

    <div class="card p-4 sm:p-6 flex flex-wrap items-center gap-4">
        <form method="POST" action="{{ route('admin.fetch.now') }}" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <button type="submit" class="btn-primary" :disabled="busy">
                <span x-show="!busy">{{ __('Fetch now') }}</span>
                <span x-show="busy" x-cloak>{{ __('Fetching…') }}</span>
            </button>
        </form>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Runs the scheduled fetch once. If Blizzard has no new snapshot yet, nothing is stored. Limit: 6 per hour.') }}</p>
        @if (session('fetch_ok'))
            <x-success>{{ session('fetch_ok') }}</x-success>
        @endif
        <x-input-error class="break-words" role="alert" :messages="session('fetch_error')" />
    </div>

    <div class="card p-4 sm:p-6">
        <h3 class="card-title">{{ __('Every fetch attempt') }}</h3>
        @if ($runs->isEmpty())
            <p class="empty">{{ __('No fetches yet. They start once Blizzard credentials are set.') }}</p>
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
                                <td><x-time :at="$r->started_at" /></td>
                                <td>{{ strtoupper($r->region) }}</td>
                                <td><span class="{{ $r->status === 'failed' ? 'text-red-600 dark:text-red-400' : '' }}">{{ $r->status }}</span></td>
                                <td class="num">{{ $r->finished_at ? $r->finished_at->diffInSeconds($r->started_at, true) : '—' }}</td>
                                <td class="num">{{ number_format($r->items) }}</td>
                                <td class="num">{{ number_format($r->auctions) }}</td>
                                <td><x-time :at="$r->last_modified" /></td>
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
