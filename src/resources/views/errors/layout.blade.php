{{-- Error pages: themed like the app, but nothing here touches the database, the session or
     the signed-in user, so the 500 page can render when those are what failed. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => trim($__env->yieldContent('title'))])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <main class="min-h-screen flex flex-col items-center justify-center px-4 text-center">
            <a href="{{ url('/') }}" aria-label="{{ config('app.name') }}">
                <x-application-logo class="h-12 w-12" />
            </a>
            <p class="mt-6 text-sm font-semibold tabular-nums text-gray-500 dark:text-gray-400">@yield('code')</p>
            <h1 class="mt-2 text-2xl font-semibold">@yield('title')</h1>
            <p class="mt-3 max-w-md text-gray-600 dark:text-gray-400">@yield('message')</p>
            <x-primary-button :href="url('/')" class="mt-8">{{ __('Go to the start page') }}</x-primary-button>
        </main>
    </body>
</html>
