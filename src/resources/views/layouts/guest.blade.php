<x-shell :title="$title" body-class="ledger-body font-sans antialiased">
    <div class="relative flex min-h-screen flex-col items-center justify-center px-4 pb-10 pt-16">
        <x-theme-toggle class="absolute right-4 top-4" />

        <a href="/" aria-label="{{ config('app.name') }}" class="relative flex flex-col items-center gap-3">
            <x-application-logo class="h-16 w-16 rounded-2xl shadow-lg" />
            <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
        </a>

        <div class="card relative mt-6 w-full overflow-hidden px-6 py-6 sm:max-w-md">
            {{ $slot }}
        </div>

        <x-legal-links class="relative mt-6" />
        <x-site-footer class="relative mt-2 justify-center" />
    </div>
</x-shell>
