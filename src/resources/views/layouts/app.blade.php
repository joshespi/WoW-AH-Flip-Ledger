<x-shell :title="$title" body-class="font-sans antialiased bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-200">
    <div class="min-h-screen">
        @include('layouts.navigation')

        <div class="flex min-h-screen flex-col lg:ps-64" @auth @unless (auth()->user()->viewsAll()) data-game="{{ auth()->user()->activeGame() }}" @endunless @endauth>
            @if (auth()->user()?->isBeingImpersonated())
                <x-session-banner tone="red" :action="route('impersonate.stop')" :button="__('Stop viewing as this user')">
                    {{ __('Viewing as :name (:email), read-only. This is logged.', ['name' => auth()->user()->name, 'email' => auth()->user()->email]) }}
                    @if (session('read_only_blocked'))<strong class="ms-2">{{ __('Changes are not allowed in this view.') }}</strong>@endif
                </x-session-banner>
            @elseif (auth()->user()?->is_demo)
                <x-session-banner :action="route('logout')" :button="__('Leave the demo')">
                    @if (session('read_only_blocked'))
                        <strong>{{ __('The demo is read-only.') }}</strong>
                    @else
                        {{ __('You are viewing the demo: sample data, read-only.') }}
                    @endif
                </x-session-banner>
            @endif

            @auth
                <livewire:freshness-strip />
                <x-game-banner :games="$games" />
            @endauth

            <!-- Page Heading: the header slot, else the page title -->
            @if (isset($header) || filled($title))
                <header class="px-4 pt-8 sm:px-6 lg:px-8">
                    @isset($header)
                        {{ $header }}
                    @else
                        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-gray-50">{{ $title }}</h1>
                    @endisset
                </header>
            @endif

            <!-- Page Content: every page sits in the same container -->
            <main class="grow py-6">
                <div class="space-y-6 px-4 sm:px-6 lg:px-8">
                    {{ $slot }}
                </div>
            </main>

            <footer class="px-4 pb-6 sm:px-6 lg:px-8">
                <x-site-footer privacy />
            </footer>
        </div>
    </div>
</x-shell>
