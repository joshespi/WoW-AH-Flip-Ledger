<x-shell :title="$title" body-class="font-sans text-gray-900 dark:text-gray-100 antialiased">
    <div class="relative flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 py-10 dark:bg-gray-950">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-72 bg-gradient-to-b from-indigo-400/10 to-transparent" aria-hidden="true"></div>
        <x-theme-toggle class="absolute right-4 top-4" />

        <a href="/" aria-label="{{ config('app.name') }}" class="relative flex flex-col items-center gap-3">
            <x-application-logo class="h-16 w-16 rounded-2xl shadow-lg" />
            <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
        </a>

        <div class="card relative mt-6 w-full overflow-hidden px-6 py-6 sm:max-w-md">
            {{ $slot }}
        </div>

        <x-legal-links class="relative mt-6" />
    </div>
</x-shell>
