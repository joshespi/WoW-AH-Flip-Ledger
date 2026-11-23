<x-public-layout :title="__('About')">
    <article class="legal">
        <h1 class="page-title">{{ __('About') }}</h1>

        <p>{{ config('app.name') }} is a ledger for World of Warcraft auction house traders. It answers one question: did my flips actually make gold?</p>

        <h2>How it works</h2>
        <ul class="list-disc ps-5 space-y-2">
            <li><strong>Your sales</strong> come from your own auction activity, recorded by this site's in-game addon or by TradeSkillMaster. Upload the saved file by hand. The ledger reads sales, expirations and cancellations, then discards the file.</li>
            <li><strong>Your buys</strong> are entered by you (typed, pasted from chat, or imported from a CSV), because TSM's own buy log double-counts commodity lots. The addon's recorded buys, and TSM's, are offered as suggestions you can accept in Reconcile.</li>
            <li><strong>Costs</strong> use a weighted average. Profit is counted after the 5% auction house cut.</li>
            <li><strong>Market prices</strong> are fetched from Blizzard's auction house data every hour. Recent, typical, 60-day and sold-per-day figures are our own estimates built from those snapshots, not TradeSkillMaster's numbers, and are shown only once an item has enough history. Forever and Classic have no Blizzard feed, so their prices come from what your own addon sees when you open the auction house (yours only, never pooled with other players'); history there is as complete as your scans.</li>
        </ul>

        <h2>Built on TradeSkillMaster</h2>
        <p>This site started from <a class="underline" href="https://www.tradeskillmaster.com" rel="noopener noreferrer" target="_blank">TradeSkillMaster</a>'s record of every auction you post, sell and cancel, and you can still import it, including your history from before you installed our addon. TSM does the posting, scanning and crafting that this ledger does not, and its team keeps it working through every patch. Where both record the same period, TSM's rows are the ones kept. We add a profit-and-loss view on top, and credit for that record goes to them.</p>

        <h2>Coverage</h2>
        <p>The ledger reports on in-game gold for commodity items in the US region. You manage auctions in the game and upload the saved data here.</p>

        <p class="pt-2">
            {{ __('Not affiliated with Blizzard Entertainment or TradeSkillMaster. World of Warcraft is a trademark of Blizzard Entertainment.') }}
            See the <a class="underline" href="{{ route('privacy') }}">privacy notice</a> and <a class="underline" href="{{ route('terms') }}">terms</a>.
        </p>

        @if ($contact = config('ledger.contact_email'))
            <p>Questions: <a class="underline" href="mailto:{{ $contact }}">{{ $contact }}</a></p>
        @endif
    </article>
</x-public-layout>
