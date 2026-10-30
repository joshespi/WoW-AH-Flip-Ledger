{{-- How old the data is: TSM only writes its file on /reload or logout, so a stale warning
     is the reminder to do that and send it. --}}
@php($f = $freshness)

<div class="border-b border-gray-200 bg-white font-mono text-[11px] text-gray-600 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-400">
    <div class="mx-auto max-w-[96rem] px-4 py-2 sm:px-6 lg:px-8 flex flex-wrap items-center gap-x-6 gap-y-1">
        <span>
            <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('TSM saved') }}</span>
            @if ($f->tsmSavedAt === null)
                <span>{{ __('never') }}</span>
                <a href="{{ route('uploads.index') }}" class="underline">{{ __('Upload your data') }}</a>
            @else
                <span class="tabular-nums">{{ $when($f->tsmSavedAt) }}</span>
                <span>({{ $f->ago($f->tsmSavedAt) }})</span>
                @if ($f->tsmStale())
                    <span class="ms-1 inline-flex items-center rounded bg-amber-100 dark:bg-amber-900/40 px-1.5 py-0.5 font-medium text-amber-800 dark:text-amber-300"
                          title="{{ __('TSM writes this file only on /reload or logout. Do that in game, then upload again.') }}">
                        {{ __('Stale: /reload in game, then upload') }}
                    </span>
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
                <span class="ms-1 inline-flex items-center rounded bg-amber-100 dark:bg-amber-900/40 px-1.5 py-0.5 font-medium text-amber-800 dark:text-amber-300"
                      title="{{ __('Prices refresh hourly from the Blizzard auction house. Until the next successful fetch, the numbers shown are the last good ones.') }}">
                    {{ $f->lastFetchFailed ? __('Price fetch failing') : __('Prices stale') }}
                </span>
            @endif
        </span>

        <a href="{{ route('uploads.index') }}" class="ms-auto underline hover:text-gray-900 dark:hover:text-gray-100">{{ __('Upload') }}</a>
    </div>
</div>
