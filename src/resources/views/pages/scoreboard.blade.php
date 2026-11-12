<x-public-layout :title="__('Scoreboard')">
    @php
        // [query key, aria label, key => label, current]; a single game needs no switch.
        $groups = array_filter([
            count($games) > 1 ? ['game', __('Game'), \App\Support\Game::labels($games), $game] : null,
            ['metric', __('Rank by'), \App\Services\ScoreboardService::metricLabels(), $metric],
            ['period', __('Period'), \App\Services\ScoreboardService::periodLabels(), $period],
        ]);
        $link = fn (array $q) => route('scoreboard', array_merge(['game' => $game, 'period' => $period, 'metric' => $metric], $q));
        $cols = [
            [__('Rank'), 'num'],
            [__('Player'), 'text'],
            [__('Realized P/L'), 'num', __('Profit on sales after the 5% AH cut, from the buy prices each player entered.')],
            [__('Sales'), 'num', __('Gold taken in from sales, after the 5% AH cut.')],
            [__('Units'), 'num'],
            [__('Flips'), 'num', __('Sales and write-offs that realized a profit or loss.')],
        ];
    @endphp

    <div class="space-y-6">
        <div>
            <h1 class="page-title">{{ __('Scoreboard') }}</h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Players who chose to be listed, under a nickname they picked. Everyone else is not shown.') }}
                <a class="underline" href="{{ route('privacy') }}#scoreboard">{{ __('Privacy') }}</a>
            </p>
        </div>

        <div class="space-y-3">
            @foreach ($groups as [$param, $aria, $options, $current])
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ $aria }}">
                    @foreach ($options as $key => $label)
                        <a href="{{ $link([$param => $key]) }}" class="seg" @if ($key === $current) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="card p-4 sm:p-6">
            @if ($rows === [])
                <p class="empty">{{ __('Nobody is listed here yet.') }}</p>
            @else
                <div class="max-h-[75vh] overflow-auto" x-data="sortableTable">
                    <table class="data-table data-table-sticky">
                        <caption class="sr-only">{{ __('Scoreboard. Use the column buttons to sort.') }}</caption>
                        <thead>
                            <tr><x-sort-headers :cols="$cols" /></tr>
                        </thead>
                        <tbody x-ref="body">
                            @foreach ($rows as $r)
                                <tr>
                                    <td data-v="{{ $r['rank'] }}" class="num">{{ $r['rank'] }}</td>
                                    <td data-v="{{ $r['nickname'] }}" class="text-gray-900 dark:text-gray-100">
                                        {{ $r['nickname'] }}
                                        @if ($r['user_id'] === $me)
                                            <x-chip :type="__('you')" tone="indigo" class="ms-1" />
                                        @endif
                                    </td>
                                    <td data-v="{{ $r['pnl'] }}" class="num"><x-money :copper="$r['pnl']" pnl /></td>
                                    <td data-v="{{ $r['sales'] }}" class="num"><x-money :copper="$r['sales']" /></td>
                                    <td data-v="{{ $r['units'] }}" class="num">{{ number_format($r['units']) }}</td>
                                    <td data-v="{{ $r['flips'] }}" class="num">{{ number_format($r['flips']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="space-y-1 note">
            @if ($updated)
                <p>{{ __('Updated :time', ['time' => $updated->format('Y-m-d H:i T')]) }}. {{ __('Refreshed hourly.') }}</p>
            @endif
            <p>{{ __('P/L uses the buy prices each player entered, so it is only as accurate as their records.') }}</p>
            <p>
                @auth
                    <a class="underline" href="{{ route('profile.edit') }}">{{ __('Join or leave from your Profile.') }}</a>
                @else
                    <a class="underline" href="{{ route('register') }}">{{ __('Sign up') }}</a> {{ __('and turn the scoreboard on in your Profile to be listed.') }}
                @endauth
            </p>
        </div>
    </div>
</x-public-layout>
