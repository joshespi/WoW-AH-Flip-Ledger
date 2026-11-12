<x-app-layout :title="__('Admin')" games="any">
    @include('admin.partials.tabs')

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-tile :label="__('Users')" :hint="__(':v verified, :n new in 7 days', ['v' => $o['users']['verified'], 'n' => $o['users']['new_7d']])">{{ number_format($o['users']['total']) }}</x-stat-tile>
        <x-stat-tile :label="__('Have uploaded')" :hint="__('users with a successful upload')">{{ number_format($o['users']['with_upload']) }}</x-stat-tile>
        <x-stat-tile :label="__('Uploads, 24 h')">
            <x-slot:hint><span @class(['text-red-600 dark:text-red-400' => $o['uploads']['failed_24h'] > 0])>{{ __(':n failed', ['n' => $o['uploads']['failed_24h']]) }}</span></x-slot:hint>
            {{ number_format($o['uploads']['ok_24h']) }}
        </x-stat-tile>
        <x-stat-tile :label="__('Items')" :hint="__(':n without a name yet', ['n' => number_format($o['items']['unnamed'])])">{{ number_format($o['items']['total']) }}</x-stat-tile>
    </div>

    @include('admin.partials.fetch-health', ['health' => $o['fetch']])

    @if ($o['backup']['configured'])
        <div class="card p-4 sm:p-6">
            <div class="card-head">
                <h3 class="card-title">{{ __('Database backup') }}</h3>
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
        <div class="card-head"><h3 class="card-title">{{ __('Latest failed uploads') }}</h3>
            <a class="link text-sm" href="{{ route('admin.uploads', ['failed' => 1]) }}">{{ __('All failures') }}</a></div>
        @if ($o['failed_uploads'] === [])
            <p class="empty">{{ __('No failed uploads.') }}</p>
        @else
            @include('admin.partials.uploads-table', ['uploads' => $o['failed_uploads']])
        @endif
    </div>

    <div class="card p-4 sm:p-6">
        <h3 class="card-title">{{ __('Admin activity') }}</h3>
        @if ($o['audit'] === [])
            <p class="empty">{{ __('Nothing yet.') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Recent admin actions on accounts.') }}</caption>
                    <thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Admin') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col">IP</th></tr></thead>
                    <tbody>
                        @foreach ($o['audit'] as $a)
                            <tr>
                                <td><x-time :at="$a->created_at" /></td>
                                <td>{{ $a->admin_email }}</td>
                                <td>{{ $a->action }}</td>
                                <td>{{ $a->target_email }}</td>
                                <td class="text-gray-500 dark:text-gray-400">{{ $a->ip }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
