<x-public-layout :title="__('Stats')">
    @php
        $days = $stats['days'];
        $chart = [
            'labels' => array_column($days, 'day'),
            'series' => [
                'active' => ['label' => __('Active users'), 'data' => array_column($days, 'active')],
                'sales' => ['label' => __('Sales logged'), 'data' => array_column($days, 'sales')],
                'buys' => ['label' => __('Buys recorded'), 'data' => array_column($days, 'buys')],
                'uploads' => ['label' => __('Uploads'), 'data' => array_column($days, 'uploads')],
            ],
        ];
    @endphp

    <div class="space-y-6">
        <div>
            <h1 class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ __('Stats') }}</h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Anonymous totals across all players. No names, items or gold amounts.') }}
                <a class="underline" href="{{ route('privacy') }}#stats">{{ __('How we count') }}</a>
            </p>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-stat-tile :label="__('Accounts')">{{ number_format($stats['accounts']) }}</x-stat-tile>
            <x-stat-tile :label="__('Active today')">{{ number_format($stats['active_today']) }}</x-stat-tile>
            <x-stat-tile :label="__('Active, 7 days')">{{ number_format($stats['active_7d']) }}</x-stat-tile>
            <x-stat-tile :label="__('Active, 30 days')">{{ number_format($stats['active_30d']) }}</x-stat-tile>
        </div>

        <div class="card p-4 sm:p-6 text-gray-600 dark:text-gray-300" x-data="statsChart(@js($chart))">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Last 30 days (UTC)') }}</h2>
            <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="{{ __('Chart series') }}">
                @foreach ($chart['series'] as $key => $s)
                    <button type="button" @click="select('{{ $key }}')" :aria-pressed="range === '{{ $key }}'"
                            :class="range === '{{ $key }}' ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-gray-200 dark:border-gray-700'"
                            class="rounded-md border px-3 py-2 text-sm">
                        {{ $s['label'] }}
                        <span class="ms-1 font-mono text-gray-500 dark:text-gray-400">{{ number_format(array_sum($s['data'])) }}</span>
                    </button>
                @endforeach
            </div>
            <div class="relative mt-4 h-64">
                <canvas x-ref="canvas" role="img" aria-label="{{ __('Daily counts for the last 30 days') }}"></canvas>
            </div>

            <details class="mt-4 text-sm">
                <summary class="cursor-pointer text-gray-600 dark:text-gray-400">{{ __('Show as a table') }}</summary>
                <div class="mt-2 max-h-80 overflow-auto">
                    <table class="data-table data-table-sticky">
                        <caption class="sr-only">{{ __('Daily counts for the last 30 days') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Day') }}</th>
                                @foreach ($chart['series'] as $s)
                                    <th scope="col" class="num">{{ $s['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_reverse($days) as $d)
                                <tr>
                                    <td>{{ $d['day'] }}</td>
                                    @foreach (array_keys($chart['series']) as $k)
                                        <td class="num">{{ number_format($d[$k]) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            @if ($stats['tracked_since'])
                {{ __('Active users are tracked since :date.', ['date' => $stats['tracked_since']]) }}
            @endif
            {{ __('Accounts counts verified accounts, not the demo.') }}
        </p>
    </div>
</x-public-layout>
