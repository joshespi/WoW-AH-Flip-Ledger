<x-public-layout :title="__('About')">
    <article class="max-w-2xl space-y-4 text-gray-700 dark:text-gray-300">
        <h1 class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ __('About') }}</h1>

        <p>{{ config('app.name') }} is a ledger for World of Warcraft auction house traders. It answers one question: did my flips actually make gold?</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">How it works</h2>
        <ul class="list-disc ps-5 space-y-2">
            <li><strong>Your sales</strong> come from your own TradeSkillMaster data, which you send with a small companion program or by uploading the file by hand. Sales, expirations and cancellations are read from it; the file itself is not kept.</li>
            <li><strong>Your buys</strong> are entered by you (typed, pasted from chat, or imported from a CSV), because TSM's own buy log double-counts commodity lots. You can reconcile TSM's suggestions against them.</li>
            <li><strong>Costs</strong> use a weighted average. Profit is counted after the 5% auction house cut.</li>
            <li><strong>Market prices</strong> are fetched from Blizzard's auction house data every hour. Recent, typical, 60-day and sold-per-day figures are our own estimates built from those snapshots, not TradeSkillMaster's numbers, and are shown only once an item has enough history.</li>
        </ul>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Built on TradeSkillMaster</h2>
        <p>This site only exists because of <a class="underline" href="https://www.tradeskillmaster.com" rel="noopener noreferrer" target="_blank">TradeSkillMaster</a>. TSM is the addon that records every auction you post, sell and cancel, and this ledger is built on that record. If you flip on the auction house, you should be running TSM: it does the posting, scanning and crafting that this ledger does not, and its team keeps it working through every patch. We add a profit-and-loss view on top, and credit for the data goes to them.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">What it is not</h2>
        <p>It does not post, buy or cancel anything, writes nothing to your WoW folder unless you turn on the optional in-game addon (then one data file, in the addon's own folder), and is not financial advice (it is about in-game gold). It covers commodity items in the US region only.</p>

        <p class="pt-2">
            {{ __('Not affiliated with Blizzard Entertainment or TradeSkillMaster. World of Warcraft is a trademark of Blizzard Entertainment.') }}
            See the <a class="underline" href="{{ route('privacy') }}">privacy notice</a> and <a class="underline" href="{{ route('terms') }}">terms</a>.
        </p>

        @if ($contact = config('ledger.contact_email'))
            <p>Questions: <a class="underline" href="mailto:{{ $contact }}">{{ $contact }}</a></p>
        @endif
    </article>
</x-public-layout>
