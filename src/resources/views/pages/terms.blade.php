<x-public-layout :title="__('Terms')">
    <article class="max-w-2xl space-y-4 text-gray-700 dark:text-gray-300">
        <h1 class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ __('Terms') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Last updated 27 October 2026</p>

        <p>By creating an account or using {{ config('app.name') }} you agree to these terms.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">The service</h2>
        <p>{{ config('app.name') }} is a hobby tool for tracking in-game World of Warcraft auction house trades. It is provided as is, without warranties, and may change, be unavailable, or be shut down. Prices and estimates (recent, typical, 60-day and sold per day) are approximations built from Blizzard's data and may be wrong or late. They are not financial advice, and you are responsible for your own in-game decisions.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Your account and data</h2>
        <ul class="list-disc ps-5 space-y-2">
            <li>Keep your password and uploader tokens to yourself; you are responsible for activity on your account.</li>
            <li>Your data stays yours. You allow us to store and process it only to run the service for you, as described in the <a class="underline" href="{{ route('privacy') }}">privacy notice</a>. You can export or delete it at any time.</li>
            <li>Only upload data from your own game account.</li>
        </ul>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Acceptable use</h2>
        <p>Do not attack, overload or probe the service, bypass its limits, scrape it, upload malicious or fabricated files, access anyone else's data, or use it to break the rules of the game or of Blizzard's or TradeSkillMaster's services. We may limit or close accounts that do.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Not affiliated</h2>
        <p>This site is not made by, endorsed by or connected to Blizzard Entertainment or TradeSkillMaster. World of Warcraft is a trademark of Blizzard Entertainment.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Liability</h2>
        <p>To the extent the law allows, the operator is not liable for lost in-game gold, lost data, or any indirect or consequential loss from using the service. Keep your own copy of anything you care about; the export in Settings makes that easy.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Changes</h2>
        <p>These terms may change. If they do, the date above changes, and continuing to use the service means you accept the new version.
            @if ($contact = config('ledger.contact_email'))
                Contact: <a class="underline" href="mailto:{{ $contact }}">{{ $contact }}</a>.
            @endif
        </p>
    </article>
</x-public-layout>
