@foreach ($health as $h)
    <div class="card p-4 sm:p-6">
        <div class="card-head">
            <h3 class="card-title">{{ __('Blizzard price fetch: :region', ['region' => strtoupper($h['region'])]) }}</h3>
            @if (! $h['configured'])
                <x-chip type="Not configured" tone="gray" title="{{ __('BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET are not set, so fetches stay idle.') }}" />
            @elseif ($h['stale'])
                <x-chip type="Stale or failing" tone="red" />
            @else
                <x-chip type="Healthy" tone="green" />
            @endif
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div><dt class="dl-label">{{ __('Last good fetch') }}</dt>
                <dd>@if ($h['good']?->finished_at)<x-time :at="$h['good']->finished_at" />@else{{ __('never') }}@endif
                    @if ($h['age'] !== null)<span class="text-gray-500 dark:text-gray-400">({{ intdiv($h['age'], 60) }} min)</span>@endif</dd></div>
            <div><dt class="dl-label">{{ __('Last run') }}</dt>
                <dd>{{ $h['last']?->status ?? '—' }}</dd></div>
            <div><dt class="dl-label">{{ __('Runs / failed, 24 h') }}</dt>
                <dd>{{ $h['runs_24h'] }} / <span class="{{ $h['failed_24h'] ? 'text-red-600 dark:text-red-400' : '' }}">{{ $h['failed_24h'] }}</span></dd></div>
            <div><dt class="dl-label">{{ __('Items with stats') }}</dt>
                <dd>{{ number_format($h['stats']) }}</dd></div>
        </dl>
        @if ($h['last']?->status === 'failed' && $h['last']->error)
            <p class="mt-3 text-sm text-red-600 dark:text-red-400 break-words">{{ $h['last']->error }}</p>
        @endif
    </div>
@endforeach
