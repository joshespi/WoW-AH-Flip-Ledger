<x-public-layout :title="__('Privacy')">
    <article class="max-w-2xl space-y-4 text-gray-700 dark:text-gray-300">
        <h1 class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ __('Privacy') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Last updated 4 November 2026</p>

        <p>This explains what {{ config('app.name') }} stores about you, why, and how to get it out or have it deleted.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">What we store</h2>
        <ul class="list-disc ps-5 space-y-2">
            <li><strong>Account:</strong> your name, email address and a hash of your password (never the password), plus your settings: timezone, region, target margin, market share and the stale-data warning time.</li>
            <li><strong>Uploader tokens:</strong> a hash of each token, its name, and when it was last used. Tokens can only upload.</li>
            <li><strong>From the addon's record or your TradeSkillMaster data:</strong> your auction sales, expired and cancelled auctions, your auction buys (kept only as suggestions) and your inventory counts: item, quantity, price, time and realm. <strong>Buyer and seller names are not stored.</strong> The addon's file also holds an anonymous label per character (a hash, not the name) and item names it has seen; neither is stored. The uploaded file is read in memory and then discarded; it is not kept.</li>
            <li><strong>What you enter:</strong> your buys, notes, watchlist items, targets and groups.</li>
            <li><strong>Upload history:</strong> when each upload arrived, its size, whether it worked, any error message, the uploader version and a short label derived from your WoW account folder (not the folder name itself).</li>
            <li><strong>Technical data:</strong> your IP address is used to limit abuse, and the web server keeps ordinary request logs for security and troubleshooting.</li>
        </ul>
        <p>Item prices and names come from Blizzard's public auction house data and are shared by everyone. They are not personal data, and none of your data is sent to Blizzard.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Cookies and tracking</h2>
        <p>The site sets a session cookie and a security (CSRF) cookie so you can stay signed in. Your light, dark or system theme choice is kept in your browser's local storage. There are no analytics, advertising or tracking scripts, and no pages load anything from third parties.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Who else sees it</h2>
        <p>We do not sell or share your data. Email (address verification, password resets, and price alerts if you turn them on in Settings) is sent through an email delivery provider, which receives your address and the message. Hosting and backups are run by the site operator.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Your choices</h2>
        <ul class="list-disc ps-5 space-y-2">
            <li><strong>Export:</strong> Settings, then Download my data, gives you everything above as CSV files.</li>
            <li><strong>Delete:</strong> deleting your account on the Profile page removes your account, tokens, trades, sales, watchlist and upload history immediately. Copies in backups disappear when those backups expire.</li>
            <li><strong>Revoke:</strong> you can revoke any uploader token in Settings at any time.</li>
        </ul>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">The demo</h2>
        <p>The demo account is shared and read-only, and contains only sample data. Nothing you do there is saved.</p>

        <h2 class="pt-4 text-lg font-medium text-gray-900 dark:text-gray-100">Changes and contact</h2>
        <p>If this notice changes in a meaningful way, the date above changes with it.
            @if ($contact = config('ledger.contact_email'))
                Contact: <a class="underline" href="mailto:{{ $contact }}">{{ $contact }}</a>.
            @endif
        </p>
    </article>
</x-public-layout>
