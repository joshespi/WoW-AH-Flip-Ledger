<x-shell :title="$title" body-class="ledger-body font-sans antialiased">
    <div class="min-h-screen flex flex-col">
        <header class="max-w-6xl w-full mx-auto px-4 sm:px-6 py-4 flex flex-wrap items-center justify-between gap-3">
            <a href="/" class="flex items-center gap-2 font-semibold">
                <x-application-logo class="h-8 w-8" />
                <span>{{ config('app.name') }}</span>
            </a>

            <nav class="flex flex-wrap items-center gap-3 text-sm" aria-label="{{ __('Main') }}">
                <x-theme-toggle />
                @auth
                    <x-primary-button :href="route('dashboard')">{{ __('Open dashboard') }}</x-primary-button>
                @else
                    <a href="{{ route('login') }}" class="font-medium text-gray-700 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white">{{ __('Log in') }}</a>
                    <x-primary-button :href="route('register')">{{ __('Sign up') }}</x-primary-button>
                @endauth
            </nav>
        </header>

        <main class="grow max-w-6xl w-full mx-auto px-4 sm:px-6 py-10 sm:py-16">
            {{ $slot }}
        </main>

        <footer class="max-w-6xl w-full mx-auto px-4 sm:px-6 py-6 note space-y-2">
            <x-legal-links class="flex-wrap gap-y-1" />
            <x-site-footer />
            <p>{!! __('Built on data from :tsm.', ['tsm' => '<a class="underline" href="https://www.tradeskillmaster.com" rel="noopener noreferrer" target="_blank">TradeSkillMaster</a>']) !!}</p>
            <p>{{ __('Not affiliated with Blizzard Entertainment or TradeSkillMaster. World of Warcraft is a trademark of Blizzard Entertainment.') }}</p>
        </footer>
    </div>
</x-shell>
