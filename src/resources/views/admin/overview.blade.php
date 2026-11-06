<x-app-layout :title="__('Admin')" games="any">
    @include('admin.partials.tabs')

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-4"><div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Users') }}</div><div class="text-2xl font-semibold tabular-nums">{{ number_format($o['users']['total']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __(':v verified, :n new in 7 days', ['v' => $o['users']['verified'], 'n' => $o['users']['new_7d']]) }}</div></div>
        <div class="card p-4"><div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Have uploaded') }}</div><div class="text-2xl font-semibold tabular-nums">{{ number_format($o['users']['with_upload']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('users with a successful upload') }}</div></div>
        <div class="card p-4"><div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Uploads, 24 h') }}</div><div class="text-2xl font-semibold tabular-nums">{{ number_format($o['uploads']['ok_24h']) }}</div>
            <div class="text-xs {{ $o['uploads']['failed_24h'] ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">{{ __(':n failed', ['n' => $o['uploads']['failed_24h']]) }}</div></div>
        <div class="card p-4"><div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Items') }}</div><div class="text-2xl font-semibold tabular-nums">{{ number_format($o['items']['total']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __(':n without a name yet', ['n' => number_format($o['items']['unnamed'])]) }}</div></div>
    </div>

    @include('admin.partials.fetch-health', ['health' => $o['fetch']])

    @if ($o['backup']['configured'])
        <div class="card p-4 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Database backup') }}</h3>
                @if ($o['backup']['stale'])
                    <x-chip type="Stale or missing" tone="red" />
                @else
                    <x-chip type="Healthy" tone="green" />
                @endif
            </div>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                @if ($o['backup']['last'])
                    {{ __('Last good dump: :when (:h h ago).', ['when' => auth()->user()->local($o['backup']['last'])->format('Y-m-d H:i'), 'h' => $o['backup']['age_hours']]) }}
                @else
                    {{ __('No successful dump has been recorded yet.') }}
                @endif
            </p>
        </div>
    @endif

    <div class="card p-4 sm:p-6">
        <div class="flex items-baseline justify-between"><h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Latest failed uploads') }}</h3>
            <a class="text-sm underline text-gray-600 dark:text-gray-400" href="{{ route('admin.uploads', ['failed' => 1]) }}">{{ __('All failures') }}</a></div>
        @if ($o['failed_uploads'] === [])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('No failed uploads.') }}</p>
        @else
            @include('admin.partials.uploads-table', ['uploads' => $o['failed_uploads']])
        @endif
    </div>

    <div class="card p-4 sm:p-6">
        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Admin activity') }}</h3>
        @if ($o['audit'] === [])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing yet.') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Recent admin actions on accounts.') }}</caption>
                    <thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Admin') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col">IP</th></tr></thead>
                    <tbody>
                        @foreach ($o['audit'] as $a)
                            <tr>
                                <td class="whitespace-nowrap">{{ auth()->user()->local($a->created_at)->format('Y-m-d H:i') }}</td>
                                <td>{{ $a->admin_email }}</td>
                                <td>{{ $a->action }}</td>
                                <td>{{ $a->target_email }}</td>
                                <td class="text-gray-500">{{ $a->ip }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
