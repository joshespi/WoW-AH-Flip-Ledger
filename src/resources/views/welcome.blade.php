<x-public-layout>
    <p class="eyebrow text-indigo-500 dark:text-indigo-400">{{ __('Auction house flip ledger') }}</p>
    <h1 class="mt-3 max-w-3xl text-4xl font-semibold tracking-tight sm:text-5xl">
        {{ __('Know whether your auction house flips actually made gold.') }}
    </h1>
    <p class="mt-5 max-w-2xl text-lg text-gray-600 dark:text-gray-400">
        {{ __('A ledger for World of Warcraft commodity traders: weighted-average cost, realized and unrealized profit after the AH cut, and today\'s prices next to what you paid.') }}
    </p>

    @guest
        <div class="mt-8 flex flex-wrap gap-3">
            <x-primary-button :href="route('register')">{{ __('Create a free account') }}</x-primary-button>
            <x-secondary-button :href="route('login')">{{ __('Log in') }}</x-secondary-button>
            @if ($demo)
                <form method="post" action="{{ route('demo') }}">
                    @csrf
                    <x-secondary-button type="submit">{{ __('Try the demo') }}</x-secondary-button>
                </form>
            @endif
        </div>
        @if ($demo)
            <p class="mt-3 note">{{ __('The demo shows sample data, read-only. No account needed.') }}</p>
        @endif
    @endguest

    <div class="card mt-12 overflow-hidden" aria-hidden="true">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2.5 dark:border-gray-800">
            <span class="eyebrow">{{ __('Sample positions') }}</span>
            <span class="font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ __('example data') }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th scope="col">{{ __('Item') }}</th><th scope="col" class="num">{{ __('Held') }}</th><th scope="col" class="num">{{ __('Break-even') }}</th><th scope="col" class="num">{{ __('Floor') }}</th><th scope="col" class="num">{{ __('vs break-even') }}</th></tr></thead>
                <tbody>
                    @foreach ([['Silvergrace Ore', '4,200', 385200, 441000, 0.158], ['Mycobloom', '1,150', 610500, 579000, -0.052], ['Luredrop', '860', 123300, 134700, 0.092]] as [$item, $held, $breakEven, $floor, $vs])
                        <tr><td>{{ $item }}</td><td class="num">{{ $held }}</td><td class="num"><x-money :copper="$breakEven" /></td><td class="num"><x-money :copper="$floor" /></td><td class="num"><x-pct :value="$vs" /></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-12 grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <h2 class="font-medium">{{ __('Your sales, recorded for you') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Our in-game addon records your buys, sales, expirations and cancellations, or import TradeSkillMaster\'s saved data. Upload the saved file and it is read straight away. The file itself is never kept.') }}</p>
        </div>
        <div class="card p-5">
            <h2 class="font-medium">{{ __('Live market prices') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Commodity floors come from the Blizzard auction house every hour, so every position shows its break-even against the current floor.') }}</p>
        </div>
        <div class="card p-5">
            <h2 class="font-medium">{{ __('Hands-off uploads') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('An optional companion app sends your data after each /reload. It reads one file and touches nothing else in your WoW folder, apart from one data file for the optional in-game addon if you turn that on.') }}</p>
        </div>
    </div>
</x-public-layout>
