{{-- How old the data is: the game only writes its files on /reload or logout, so a stale warning
     is the reminder to do that and send it. --}}
@php($f = $freshness)

<div class="border-b border-gray-200 bg-white font-mono text-[11px] text-gray-600 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-400">
    <div class="px-4 py-2 sm:px-6 lg:px-8 flex flex-wrap items-center gap-x-6 gap-y-1">
        <span>
            <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Game saved') }}</span>
            @if ($f->tsmSavedAt === null)
                <span>{{ __('never') }}</span>
                <a href="{{ route('uploads.index') }}" class="underline">{{ __('Upload your data') }}</a>
            @else
                <span class="tabular-nums">{{ $when($f->tsmSavedAt) }}</span>
                <span>({{ $f->ago($f->tsmSavedAt) }})</span>
                @if ($f->tsmStale())
                    <x-chip :type="__('Stale: /reload in game, then upload')" tone="amber" class="ms-1 !text-[11px]" title="{{ __('The game writes this file only on /reload or logout. Do that in game, then upload again.') }}" />
                @endif
            @endif
        </span>

        <span>
            <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Prices') }}</span>
            @if ($f->pricesAt === null)
                <span>{{ __('not fetched yet') }}</span>
            @else
                <span class="tabular-nums">{{ $when($f->pricesAt) }}</span>
                <span>({{ $f->ago($f->pricesAt) }})</span>
            @endif
            @if ($f->pricesStale())
                <x-chip :type="$f->lastFetchFailed ? __('Price fetch failing') : __('Prices stale')" tone="amber" class="ms-1 !text-[11px]" title="{{ __('Prices refresh hourly from the Blizzard auction house. Until the next successful fetch, the numbers shown are the last good ones.') }}" />
            @endif
        </span>

        <a href="{{ route('uploads.index') }}" class="ms-auto underline hover:text-gray-900 dark:hover:text-gray-100">{{ __('Upload') }}</a>
    </div>
</div>
