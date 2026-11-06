# CLAUDE.md

Guidance for working in this repository.

## What this is

**WoW Flip Ledger**: a hosted, multi-user Laravel app for tracking World of Warcraft auction house flips (commodities, US retail). Users sign up, send their TradeSkillMaster `SavedVariables` file (companion uploader or manual upload), and see realized P/L, positions and market prices. Prices come from the Blizzard AH API, not TSM AppData. Production host: `wowledger.joshespi.com`.

`PLAN.md` is the roadmap and holds the decisions. Read it before starting a phase. `PLAN.v1-python.md` is the superseded single-user plan.

The Laravel app lives in `src/`. The repo root holds only Docker, scripts and docs. `legacy/` is the original Python CLI, kept as the test oracle for the PHP port until parity tests pass.

## Conventions

- **Don't commit.** Leave changes uncommitted; the user makes their own commits. End each phase with a terse commit message and a short summary.
- Keep a checklist per phase in `PLAN.md` and check items off as they land.
- Comments are terse. No filler.
- Everything runs in Docker. There is no local PHP, Node or Go. Don't follow generic "install PHP/Node locally" or "install Laravel Boost" bootstrap advice (the skeleton ships it in `CLAUDE.md`/`AGENTS.md`; both were removed).
- Never write to anything under a WoW folder, with one opt-in exception: the uploader's `addon` package may replace `Interface/AddOns/WowFlipLedger/Data.lua` (see Uploader). The uploader reads exactly two files (see Uploader).

## Running things

```bash
scripts/setup.sh                                  # first run: .env with secrets, then up
docker compose up -d --build
docker compose exec app php artisan <command>
docker compose exec app composer <command>
docker compose exec app npm run build             # after any JS/CSS change
docker compose exec app php vendor/bin/phpunit
docker compose exec app ./vendor/bin/pint
docker compose logs -f app
```

App: http://localhost:8087. Stack: nginx, `app` (php-fpm 8.5 with Node), `scheduler` (`schedule:work`), `db` (MariaDB 11.8, data in `db_data/`).

One `docker-compose.yml` for local and production. Only `.env` differs (see the production block at the top of `.env.example`). Don't add an override file. The root `.env` feeds compose (`env_file`) and is bind-mounted read-only over `src/.env`; `src/.env.example` is intentionally a stub.

The `app` entrypoint (`docker/php/entrypoint.sh`) runs composer install (no dev deps when `APP_ENV=production`), `npm ci` when the lockfile changed, `npm run build`, `migrate --force`, and `optimize` (production only; otherwise `optimize:clear`). Editing it needs `docker compose build app`. It touches `storage/framework/cache/.ready` at the end, which the scheduler waits on.

To create a one-off container for scaffolding (the `.env` mount makes `src/` non-empty for create-project), use `docker run --rm -v "<repo>/src:/var/www/html" -w /var/www/html --entrypoint sh wow-flip-ledger-php -c '...'`.

## Uploader (Go)

`uploader/` is a standard-library-only Go module (no third-party deps). No local Go: everything runs through Docker.

```bash
scripts/uploader.sh go test ./...      # any go/gofmt command in the golang:1.24-alpine image
scripts/uploader.sh gofmt -l .
scripts/build-uploader.sh [version]    # vet + test + cross-compile to uploader/dist/ with SHA256SUMS
```

See `uploader/README.md` for behavior and commands. Things that bite: the uploader may read only `TradeSkillMaster.lua` and the addon's own `WowFlipLedger.lua` (both in `WTF/Account/<account>/SavedVariables`, under `_retail_` and a `_forever_` install beside it; Forever files are sent with `X-Wow-Game: forever`) and may write under the WoW folder only through `internal/addon`, which replaces `Interface/AddOns/WowFlipLedger/Data.lua` (atomically, only when the user ran `addon enable`, never creating the addon folder, never following symlinks); everything else it writes lives in the user config dir, and `TestSyncChangesOnlyDataLua` plus the `addon` tests in `main_test.go` enforce it, so keep new write paths out of every other package. The API contract it relies on is `POST /api/v1/ingest`, `GET /api/v1/ping` and `GET /api/v1/addon-feed` (status codes drive its retry/fatal logic, so changing them means changing `internal/client`); the feed is rendered to Lua by a strict serializer (`internal/addon/lua.go`: server text is escaped, never pasted into code, and `schema` must match `SupportedSchema`), so a new feed field needs no uploader change but a new shape does; builds must stay reproducible (`-trimpath`, no cgo). `WFL_SETTLE_MS`, `WFL_STARTUP_DIR`, `WFL_NO_START` and `WFL_CONFIG_DIR` exist for tests and dry runs. To test against the dev server on Windows, run the built `.exe` natively against `http://localhost:8087`.

## Prices (Blizzard)

Shared market data, keyed by region: `price_snapshots` (hourly, per commodity), `price_daily` (rollup, kept long term), `item_stats` (latest numbers plus derived estimates), `fetch_runs` (every attempt), and the `items` catalog. Commands: `prices:fetch` (snapshot, then rollup and stats), `prices:names`, `prices:rollup`, `prices:stats`, `prices:prune`, and `prices:probe` (verifies the live API, writes nothing). They are scheduled in `bootstrap/app.php` and stay idle until `BLIZZARD_CLIENT_ID` and `BLIZZARD_CLIENT_SECRET` are set.

Things that bite:
- Blizzard's response is tens of MB: it is downloaded to a temp file and stream-parsed (`CommoditiesParser`). Never `json_decode` it whole or `Http::get()->json()` it.
- A fetch is all-or-nothing and never throws out of `PriceFetchService::fetch()`; it returns a `FetchRun` (`ok`, `unchanged`, `failed`). Snapshots are identified by Blizzard's `Last-Modified`, so duplicates are impossible even if `If-Modified-Since` is ignored.
- Derived metrics (`recent`, `typical`, `hist60`, `sold_per_day_est`) are our own estimates, not TSM's. Show them only when `days_of_data >= ledger.prices.min_days`. Windows are anchored at the latest snapshot, not the clock, which also makes tests deterministic (use fixed dates).
- `items.name` can be null (created by a price snapshot before any name is known): display "Item #id". Name precedence is Blizzard over TSM over none; TSM uploads only fill nulls.
- `prices:calibrate` (compares against TSM AppData from `legacy/dump_appdata.py`) is dev-only: registered in `local`/`testing`, and its input is TSM's data, never to be committed or deployed.
- In tests, `Http::fake()` calls stack (the first matching stub wins) and a `Http::response()` object's body can be read only once, so use closures or `Http::sequence()` (see `Tests\Support\FakesBlizzard`). `withSchedule()` events appear only after the console kernel resolves the schedule (run `schedule:list` first).
- SQLite (tests) and MariaDB (runtime) both run this code; cast ids from cursors to int. After changing SQL, smoke-test on MariaDB, not just SQLite.

## Ledger screens

`PositionsService::forUser()` is the one place that turns a user's trades, TSM sales and the shared `item_stats` into a `Portfolio` of `PositionRow`s; the dashboard and positions controllers only render it. Trades are the user's own buys (`trades`, side `buy`) and corrections (`Trade::CORRECTIONS`: `adjust` removes stock at average cost with no P/L, `writeoff` books that cost as a realized loss; made on the item page through `TradesService::correctHeld()`), imported by `TradesImportService` (page, or `php artisan trades:import`). Sales come from `tsm_sales`.

Things that bite:
- **Derived metrics are gated.** `recent`, `typical`, `hist60`, `sold_per_day_est`, the below/above chips and days-to-sell are `null` until `days_of_data >= ledger.prices.min_days`; the floor, cost and P/L are always exact. Views show `<x-collecting>` or "no price yet", never a made-up number.
- **Look:** dark-first "terminal" style, one gold accent. `tailwind.config.js` remaps `gray` (cool ink) and `indigo` (gold), so existing `gray-*`/`indigo-*` classes carry the palette; do not add other accent hues. Inter and JetBrains Mono are self-hosted (`@fontsource-variable`, imported in `app.js`); figures use `font-mono` (`.num`, `<x-money>`, `<x-stat-tile>`). Buttons are `.btn-primary` / `.btn-secondary` / `.btn-danger` (the `<x-*-button>` components); the app layout is a fixed left sidebar (`layouts/navigation`, always dark) with the page in `lg:ps-64`. Default theme is dark; "system" is an explicit stored choice.
- **Theme:** light / system / dark. Tailwind `darkMode: 'class'`; `partials/theme-init` sets `<html class="dark">` before paint and `themeToggle` in `app.js` keeps it in sync. Every new view needs `dark:` variants (or use the `.card` / `.data-table` classes in `app.css`). Inputs and borders get dark defaults from base CSS.
- **Money:** `Money::segments()` holds the display rules; `Money::fmt()` and `<x-money :copper="..." pnl />` (colored g/s/c) are both built from it, and the component is tested against the golden cases. Pass `null` for "no data" (renders a dash). `<x-pct :value>` and `<x-position-status :position>` are the shared percent and chips cells.
- **Freshness header:** "Prices" comes from the newest `fetch_runs` row with status `ok`/`unchanged` (Blizzard's `last_modified`), flagged stale after `ledger.prices.stale_hours` or when the latest run failed. It never reads `item_stats`, so fake or derived stats don't affect it.
- **Sortable tables:** `x-data="sortableTable"`; each `<th scope="col" :aria-sort="ariaSort(i)">` wraps a `<button type="button" @click="sort(i, 'num'|'text')">` (keyboard and screen-reader friendly), and each body cell carries a `data-v` sort key (money cells are markup, so the number lives in the attribute). For wide tables add `data-table-sticky` and a `max-h-[75vh] overflow-auto` wrapper: the heading row and first column stay in view.
- **One `<head>`:** `partials/head` (title, favicon, theme script, Vite) is used by the app and guest layouts, the landing page and `errors/layout`. Give every page a tab title: `<x-app-layout :title="__('Positions')">` renders "Positions · WoW Flip Ledger". Error pages must not touch the database, session or user (the head skips the CSRF meta without a session).
- **Shared view bits:** the app layout renders the page title as the header unless a `header` slot is given (only `positions` has one), so don't repeat it. `<x-section :title :description [width]>` is the titled card; `<x-saved :when>` the fading "Saved."; `<x-primary-button>` and `<x-secondary-button>` render a link when given `:href` (never nest a button in an `<a>`). Show stored UTC times with `$user->local($time)->format(...)`, never by hand.
- **Shared bits:** `<x-success>` for green confirmations; `<x-application-logo>` is our mark, an `<img>` of `public/logo.png` (size it with `h-*`/`w-*`; no colour classes). `logo.png`, `favicon.ico` and `apple-touch-icon.png` are cut from `resources/branding/logo-master.jpg` (tile 84-1915px, corner radius ~245px, `#1c1d22`; small favicon sizes use a tighter crop). Re-cut from the master rather than hand-editing them.
- `User` has `$attributes` defaults that mirror the column defaults (timezone, region, target margin, stale minutes, market share). Keep them in step: a user created in the current request (registration, factories) is not reloaded from the database.
- `LedgerService::buildFromSales()` takes already-validated sales and accepts a generator; `build()` is the TSM-CSV-shaped entry point the parity tests use. Both must stay in agreement (tested).
- `LoadsLedgerFixture` (tests/Support) loads the anonymized fixture into a user (`fixtureUser(prices: true)` does user, fixture and prices in one call), seeds prices for every chip and "collecting data" state (`seedFixturePrices()`), and seeds fetch runs (`seedFetchRun()`); use it for screen and service tests instead of hand-building rows. `SeedsMarketData` (inside it) has `seedItem()` and `seedStats()`; `IngestsFiles` (tests/Support) ingests addon and TSM files for `$this->user` and counts rows (`rowsOf`), reads the span and posts to the uploader endpoint; `TempFiles::tempFile()` makes a temp file that is cleaned up after the test; `FakesBlizzard` has `TOKEN_URL`, `COMMODITIES_URL` and `ITEM_URL` for `$overrides` keys.
- `Support\Bounds` is the one place for plausibility limits (times, quantity, price, cost, item id) and the untrusted-digits check (`Bounds::count()`), shared by ingest, trade import and the ledger. `Support\Bulk` does chunked `insertOrIgnore`/`upsert`; `Support\Rows::byItem()` groups a cursor ordered by `item_id` one item at a time. Reuse them instead of re-chunking or re-grouping. Trade import errors name the CSV line (header is line 1), from the parser and the service alike.
- The uploader's 426 ("update the uploader") is the `client.version` middleware (`RequireClientVersion`) on the API routes; the controllers don't check versions. The `prices:*` commands extend `App\Console\PricesCommand` (`region()`, `credentialsSet()`).

- **Shared helpers: use these instead of writing the snippet again.**
  - Times and text: `Utc::sql($ts)` / `Utc::ts($utc)` (database times), `Sql::like($q)` (escaped `lower(col) like ?` pattern), `ItemName::display($name, $id)` ("Item #id" fallback). `User::local($t)` takes a date, epoch or UTC database string; `User::inputTime($t)` and `parseInputTime($s)` are the `datetime-local` form value in the user's timezone.
  - Limits: `Bounds::validItemId()`, `wholeNumber()` (digits typed into a form), `quantityInput()`, `tradeProblem()` (item id, qty, cost and time of a buy; hand entry and CSV import share it).
  - Tenant scoping: start every query on a user's data with `ownedBy($user)`: `Trade::ownedBy($user)` (scope from `Models\Concerns\OwnedByUser`, also on `Upload` and `WatchlistItem`) and `DB::table('tsm_sales')->ownedBy($user)` (a builder macro; pass the qualified column when joining: `->ownedBy($user, 'trades.user_id')`). Don't write `where('user_id', ...)` by hand.
  - Market gate: `MarketStats::ready($stat)` / `derived($stat, 'recent')` / `minDays()` are the only implementation of the "needs `min_days` of history" rule; use them for anything shaped like an `item_stats` row.
  - Models: `User::issueIngestToken()` (token limit included), `isBeingImpersonated()`, `FetchRun::latestGood()` / `latestFinished()`, `User::factory()->admin()` in tests.
  - Livewire: every ledger component uses `Concerns\ReportsErrors`; wrap each service call in `attempt('errorKey', fn () => ...)` so a user-facing `InvalidArgumentException` (bad input, read-only session) becomes a form error. Pages that are one component go through the `livewire-page` view (loop in `routes/web.php`); the view variable is `livewire`, never `component` (Blade reserves it).
  - Blade: the app layout already wraps every page in a full-width `py-6 space-y-6` container, so views start straight with their cards. `<x-item-link :id :name>`, `<x-chip type tone>` (green, red, amber, sky, indigo, gray), `<x-session-banner>`, `<x-shell>` (the HTML document all three layouts share), `<x-legal-links>`, and the `.field` class for hand-written inputs, selects and textareas.

## Trades and reconcile

`/trades`, `/reconcile`, `/market` and `/scan` are Livewire components (`app/Livewire`) rendered inside the normal `<x-app-layout>` by the shared `livewire-page` view. All trade writes go through `TradesService` (add, update, delete, `addPasted`), which scopes by `user_id` and applies `Bounds`; the components never touch `Trade` for writes. `ReconcileService` decides what is pending and accepts via `TradesService::add(... source 'tsm', tsm_ref)`. Components read the user from `auth()`; pass ids only, never trust a model from the client.

## Tests

```bash
docker compose exec app php vendor/bin/phpunit                        # full suite, SQLite :memory:
docker compose exec app php vendor/bin/phpunit --filter SomeTest
```

`phpunit.xml` points at `tests/bootstrap.php`, which forces the sqlite, array-session, array-cache, sync-queue test values into `$_ENV`, `$_SERVER` and `putenv` before Laravel boots. This is required: compose passes `SESSION_DRIVER=database` and the real DB credentials into the container, and phpunit's non-forced `<env>` tags don't override variables that already exist, so without it tests hit the live MariaDB or fail on missing tables. `phpunit`, `artisan test` and `composer test` are all safe. Verified: a full run adds no rows to MariaDB. Format with `./vendor/bin/pint` (stock `laravel` preset).

Prefer `tests/Feature` for HTTP-level behavior and `tests/Unit` for pure logic (money, parser, ledger).

## Architecture

Per `PLAN.md`: thin controllers, domain logic in `app/Services`, authorization via Policies (one per owned model), money as BIGINT copper, timestamps UTC and shown in the user's timezone. Every per-user table carries `user_id`. Shared tables are item catalog and price snapshots. Scheduled work is registered in `bootstrap/app.php` `withSchedule` with `onOneServer()` and `withoutOverlapping()`.

Stack details that bite:

- **Livewire provides Alpine.** `@livewireStyles` and `@livewireScripts` are in both layouts, so Alpine is on every page. `resources/js/app.js` must not import `alpinejs`; register components on `alpine:init`.
- **CSP (`SecurityHeaders`, global middleware):** scripts and style elements need the per-request nonce. `@vite`, `@livewireScripts` and `@livewireStyles` add it themselves; any other inline `<script>` or `<style>` must carry `nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"` (see `partials/theme-init`). No third-party origins, no `eval`-free Alpine: `unsafe-eval` is allowed on purpose. Inline `style=""` attributes are allowed; inline event handler attributes (`onclick=`) are not (use Alpine `@click`). After touching layouts or JS, load a page in the browser and check the console for CSP errors.
- **Production switches:** `LEDGER_FORCE_HTTPS` stays off until `TRUSTED_PROXIES` is right (an untrusted proxy makes every request look like HTTP and it redirects forever); the README's HSTS check verifies it. The scheduler also runs `alerts:send` and `health:check`; the `backup` service writes `backups/LAST_OK`, which `BackupStatus` reads (compose sets `LEDGER_BACKUP_STATUS_FILE`).
- **Tailwind v3** with PostCSS (Breeze's setup, same as the finance tracker). Don't add `@tailwindcss/vite`.
- No third-party requests at runtime: no CDN scripts, no web fonts. Chart.js comes from npm.
- `Model::preventLazyLoading()` is on outside production, so eager-load relations.
- `bootstrap/app.php` pins `trustProxies` headers (never X-Forwarded-Host) and calls `trustHosts()`. `TRUSTED_PROXIES` is the proxy's IP/CIDR, never `*`.
- `QUEUE_CONNECTION=sync` on purpose. Don't add `ShouldQueue` jobs without also adding a `queue` worker service.
- nginx caps request bodies at 2 MB, except `POST /api/v1/ingest` and `POST /uploads` (50 MB, own `location` in `docker/nginx/default.conf.template`). If you rename either route, change the regex there, `ledger.ingest.max_request_bytes` and the PHP limits in `docker/php/zz-app.ini` together.
- Uploads never add `items` rows and only name unnamed commodities the price fetch created (`IngestService::fillNames`): the catalog is shared and trade entry resolves items by name, so a user-supplied name must not be able to claim an id. Tests that need names seed the catalog first (`LoadsLedgerFixture` does; `demo:seed` names its own file's items).
- `docker/nginx/default.conf.template` is an envsubst template (`REAL_IP_FROM` = `TRUSTED_PROXIES`, set in compose). It rate-limits the two 50 MB upload locations per client before the app sees the body; the client key uses the last `X-Forwarded-For` entry only for requests from that proxy, and `REMOTE_ADDR` for PHP stays untouched (TrustProxies needs it).
- Auth throttles: `login` (per IP, on top of `LoginRequest`'s per email+IP), `password-reset` (forgot and reset), `register`. The forgot-password answer is identical for unknown addresses. Changing the email needs `current_password`. Impersonating admins cannot export (`/settings/export`, `/trades/export`).
- Ingest is synchronous (`IngestService`, ~0.3 s for a real file). Uploads are parsed in memory and never stored; rows are idempotent via `dedupe_key`.
- **Undo upload:** new `tsm_sales`/`tsm_expired`/`tsm_cancelled`/`tsm_buy_candidates` rows carry `upload_id` (null for older rows). `UploadService::undo()` deletes them and the `uploads` row, only for the newest successful upload of a game (files are cumulative and inserts skip existing keys, so an older upload's rows may be shared) and refuses when its buys were accepted into trades. Undo always rebuilds the TSM span from the TSM rows left (`TsmSpan::rebuild`; nulling it would let addon rows double-count older TSM data); inventory keeps no history, so the form offers (default on) to clear that game's snapshot. A new table written by ingest needs `upload_id` and a line in `IngestService::LOG_TABLES` (undo and the TSM-span cleanup both read it).
- The per-IP ingest limit is the global `LimitIngestByIp` middleware: Laravel's middleware priority always runs `auth` before route-level `throttle`, so a route `throttle:` can't limit bad-token floods.
- Sanctum tokens have no foreign key, so `User` deletes them in its `deleting` hook. Plain tokens look like `<id>|wfl_<secret>`.
- `TenantIsolationTest` walks every route: a new public route needs adding to its allow-list, and a new authenticated route with an id parameter needs an ownership fixture there.
- In tests, `call()` ignores `withToken()`/default headers (pass `HTTP_AUTHORIZATION`), and API calls switch the app's default guard to sanctum, so call `$this->app['auth']->forgetGuards()` (and `shouldUse('web')` before web requests) when mixing them in one test.

## Addon recorder (no TSM needed)

`addon/WowFlipLedger` records the user's own AH activity into `WowFlipLedgerDB` (SavedVariables, account-wide). `Core.lua` holds the shared helpers (`ns.db()` for the saved table with its defaults, `ns.on()` for the one event frame); the others are `Recorder.lua` (buys via `C_AuctionHouse` hooks and events; sales, expiries and cancels from the mailbox when a mail is collected) and `Inventory.lua` (bags, bank, warband bank, auctions, mail, summed over characters). Records are one string each (`kind|time|realm|item|qty|a|b`) so WoW writes one per line; `AddonParser` reads them into the same `TsmData` shape as `TsmParser`, and `IngestService` picks the parser by the file's first line.

Things that bite:
- **Run `addon/tests/run.sh`** (Docker, Lua 5.1, stubbed WoW API) after touching the addon; it also writes `addon/tests/out/WowFlipLedger.lua`. It cannot prove real client behaviour: the event payloads, mail invoice fields and bank scanning are unverified until someone plays with it (see PLAN.md Phase 12).
- **Real client layout** (checked against a file the retail client wrote): CRLF, no indentation, no `-- [n]` comments. `AddonParser` trims lines and counts braces for depth; `Tests\Support\AddonFile` writes that layout by default (`legacy: true` for tabs). Confirmed in game on 2026-11-04: buys (`COMMODITY_PURCHASE_SUCCEEDED` + quote) and sale invoices (`bid` = gross, `consignment` = the 5% cut, realm and count correct) work.
- Sale mail has the item *name*, not the id: the addon resolves it from a learned name->id map and holds back ambiguous or unknown names (`unresolved`). The uploaded file carries them; `IngestService::resolveSaleNames()` gives one an id only when exactly one commodity in `items` has that name (case-insensitive), so a shared name is skipped, never guessed.
- **TSM wins where it has data.** Rows carry `origin` (`tsm`/`addon`); `tsm_spans` (one row per user, game and WoW `account`) is the span TSM has covered, and `Support\TsmSpan` is its only reader and writer (get, covering, widen, rebuild). Addon rows inside it are not stored, and a TSM upload that widens it deletes addon rows now inside it, after handing an ignored addon buy's status to its pending TSM twin. TSM's data is per WoW account, so a span covers only the same account's addon rows: log rows carry `account` (`TsmSpan::account()`: the uploader's label without its game and `-addon` tags); `''` is unknown (manual uploads, older rows) and matches every account both ways. Addon buys still land in `tsm_buy_candidates` (pending, exact `cost_copper`), never in `trades` directly. TSM dedupe keys are unchanged; addon keys are prefixed `addon:`. Inventory: a TSM file replaces the whole snapshot; an addon file only updates the items it saw (its scan can miss the bank), and only if newer, and drops `inventory.origin = addon` rows it no longer lists (the addon keeps each store as last seen). The span ends at the file's mtime, or at its newest row when there is none. An unknown `X-Wow-Game` is a 422, never retail.
- No character names in the file: characters are a salted hash of the GUID. Keep it that way (the privacy page says so).
- **Game view:** which games a user sees is `users.enabled_games` (`User::enabledGames()`; set in Settings, or switched on by an upload for that game). The sidebar switch is `User::gameView()` (session first, then `users.game_view`; always one enabled game, or `all` with two or more). Pages that cannot mix games use `User::activeGame()` (All = the first enabled game, and `<x-game-banner>` says so). Each page says how it uses the switch on its layout: `<x-app-layout games="any">` (shows every game or none in particular) or `games="market"` (also needs the price feed); the default is one game. The Livewire pages set it in the `routes/web.php` loop. Games are Retail, Forever, Classic (`Support\Game`); `Game::hasMarket()` is true only for Retail. Dashboard in Both is `CombinedService` + `combined.blade.php`. Retail = gold, Forever = Tailwind `teal` (dashed chart line), Classic = `violet` (dotted), from `Game::tone()` and `Game::chart()`; badge and card rule too: the one exception to the single-accent rule, for game identity only.
- **Games:** per-game tables carry `game` (`retail`|`forever`, default retail; `Support\Game`). Query with `->inGame($game)` (builder macro; Eloquent forwards it, so `Trade::ownedBy($u)->inGame($g)` works too); services default to retail, so a Forever row never shows up in a retail screen by accident. Ingest takes the game from `X-Wow-Game` / the upload form, never from the file. Forever dedupe keys are prefixed `forever:`; Forever has no Blizzard prices, so no market stats, and it never names items in the shared catalog. `watchlist_items`/`alert_states` and the addon feed are still retail-only.
- Packaging for CurseForge: `addon/CHANGELOG.md` must have a `## <toc version>` section (the build script checks), `addon/CURSEFORGE.md` has the project text and the release steps, `scripts/publish-curseforge.sh` uploads the same zip the site serves (never run against a real project).

## Not built yet

Built: auth, accounts (timezone, region), uploader tokens, the ingest and ping endpoints, the manual upload page, and the Go uploader. Built but not yet run against the real Blizzard API (credentials pending): the price pipeline. Also built: the trades table with CSV import, the dashboard and positions screens, the light/system/dark theme and the header freshness strip. Also built: the full trades UI (add, paste, edit, delete, export) and reconcile. Also built: market (watchlist), the falling-knife flag and scan, on `WatchlistService`, `ScanService` and `FallingKnife` (thresholds in `ledger.market`, still uncalibrated). Also built: the item page (`ItemDetailService`; its position replay must stay in step with `LedgerService`, tested), with a lazy-loaded Chart.js chart. Also built: the `/setup` wizard and uploader downloads (`UploaderBuilds` serves `src/storage/app/uploader/`, filled by `scripts/build-uploader.sh`; run it on deploy). Also built: data export and account deletion, the demo account (`demo:seed`; read-only sessions are refused by `ReadOnlySession` for plain requests and by `ReportsErrors::attempt()` for Livewire, so every new Livewire write action must go through `attempt()`; `LivewireWriteGuardTest` fails if one does not), and the About/Privacy/Terms pages. Also built: the admin area (`AdminService`, `admin:grant`, read-only impersonation with an audit trail; admin routes need no ownership fixture but any new one goes behind the `admin` middleware). Also built: price alert emails (`AlertService`, `alerts:send`; a new rule needs a column on `users`, a case in `AlertService::current()` and a row in the mail views). Also built (Phase 10): security headers and CSP, forced-HTTPS switch, secure cookies, the scheduled `backup` service with a tested restore, `health:check` admin notifications, and the self-hosting README. Left: the real proxy, DNS and certificate at deploy time, and the soft launch. See the phase checklists in `PLAN.md`.
