@foreach ($health as $h)
    <div class="card p-4 sm:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Blizzard price fetch: :region', ['region' => strtoupper($h['region'])]) }}</h3>
            @if (! $h['configured'])
                <x-chip type="Not configured" tone="gray" title="{{ __('BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET are not set, so fetches stay idle.') }}" />
            @elseif ($h['stale'])
                <x-chip type="Stale or failing" tone="red" />
            @else
                <x-chip type="Healthy" tone="green" />
            @endif
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Last good fetch') }}</dt>
                <dd>{{ $h['good']?->finished_at ? auth()->user()->local($h['good']->finished_at)->format('Y-m-d H:i') : __('never') }}
                    @if ($h['age'] !== null)<span class="text-gray-500">({{ intdiv($h['age'], 60) }} min)</span>@endif</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Last run') }}</dt>
                <dd>{{ $h['last']?->status ?? '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Runs / failed, 24 h') }}</dt>
                <dd>{{ $h['runs_24h'] }} / <span class="{{ $h['failed_24h'] ? 'text-red-600 dark:text-red-400' : '' }}">{{ $h['failed_24h'] }}</span></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Items with stats') }}</dt>
                <dd>{{ number_format($h['stats']) }}</dd></div>
        </dl>
        @if ($h['last']?->status === 'failed' && $h['last']->error)
            <p class="mt-3 text-sm text-red-600 dark:text-red-400 break-words">{{ $h['last']->error }}</p>
        @endif
    </div>
@endforeach
