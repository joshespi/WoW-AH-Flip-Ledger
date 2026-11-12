# WoW Flip Ledger: Multi-user Web App Plan

Turn `tsm_ledger.py` (single-user CLI) into a hosted Laravel app where anyone can sign up, send their TSM data, and track WoW auction house flips. The earlier single-user Python/FastAPI plan is kept in `PLAN.v1-python.md`. Its domain rules carry over below.

## Conventions

- Don't commit. Leave changes uncommitted; I make my own commits. End each phase with a terse commit message and a short summary of what changed and why.
- Keep a checklist per phase and check items off as you go.
- Comments: terse and to the point. No filler, no AI-speak.
- Everything runs in Docker. No local PHP/Node/Go. Follow `personal-finance-tracker`: repo root holds Docker and scripts, the Laravel app lives in `src/`.
- Never write to anything under the WoW folder, except the opt-in addon data file (Phase 11: `Interface/AddOns/WowFlipLedger/Data.lua`). The uploader only reads one file.
- Surface artisan/composer/npm commands as `docker compose exec app ...`.

## Decisions (made with me, don't reopen)

| Topic | Decision |
|---|---|
| Name | **WoW Flip Ledger**. Slug `wow-flip-ledger`. Display name only from `APP_NAME`. |
| Hosting | One instance hosted by me, behind a reverse proxy, at **wowledger.joshespi.com**. |
| Accounts | Open signup, email verification, Brevo SMTP. |
| Data in | Companion uploader (Go) from day one, plus manual file upload in the browser. Both hit the same ingest code. |
| Prices | **Blizzard AH API**, fetched hourly by the scheduler. No TSM AppData in the product. |
| Metrics | Sale avg, 60d hist and sold/day are **derived from our own snapshots**, labeled as estimates, and show "collecting data" until enough history exists. |
| Scope | US retail, commodities only. Schema carries a `region` column so EU can be added without a migration. |
| Uploader downloads | Served by the app itself: a `/download` route plus `SHA256SUMS`. Built in Phase 9 with the setup wizard. No GitHub repo needed. |
| Stack | Laravel 13, Livewire 4 (Trades and Market tables only), Blade, Alpine, Tailwind, Vite, Chart.js, MariaDB 11.8, nginx, php-fpm. Finance-tracker pattern: thin controllers, Services, Policies. |
| Python tool | `tsm_ledger.py` stays untouched as the test oracle until PHP matches it, then moves out. |
| Polish in | Demo and setup wizard, price alert emails, privacy and data tools, admin and health dashboard. |

## Domain primer (unchanged from v1)

- **Commodities** (mats, fish, ore) trade on one region-wide auction house. Prices are per unit.
- Money is stored as **copper** integers: 1g = 100s = 10,000c. Use BIGINT. Display as `1,234g 56s 78c`, with g/s/c colored gold/silver/copper.
- The **AH cut** is 5% of the sale price. The deposit is refunded on a sale, so it is not profit.
- **Break-even** = avg cost / 0.95. Default **target margin** is 15%, so the target sell price is avg cost × 1.15 / 0.95.
- **Rank variants** of the same item are separate item IDs, e.g. Void-Tempered Leather 238511 (R1) and 238512 (R2). Group them by name in lists and keep them as separate rows.
- **Cost basis** is weighted average cost, not FIFO.
- **Own stock**: sales beyond the tracked held quantity are gathered or pre-existing stock. Report them separately and keep them out of flip P/L.

## What changes from v1

- **No file mounts.** The server never sees the WoW folder. Data arrives as an HTTP upload of `TradeSkillMaster.lua` (~2 MB, ~200 KB gzipped).
- **Per-user data**: trades, watchlist, TSM sales, settings. **Shared data**: items and price snapshots, since commodity prices are the same for everyone in a region.
- **Prices move from TSM AppData to Blizzard.** TSM's `regionSale`, `regionSoldPerDay` and 60-day history don't exist there, so those become derived estimates. Thresholds that were tuned on TSM numbers (falling-knife 0.75 / 0.8, days-to-sell share 5%) must be recalibrated, see Phase 4.
- **Refresh button goes away.** The header shows "TSM saved HH:MM (age)" from the last upload and "Prices HH:MM (age)" from the last Blizzard fetch, plus an Upload button.
- **Timezone is per user**, not a `TZ` env var. Store UTC.
- **Untrusted input.** Files are now uploaded by strangers, so parsing must be linear-time, size-capped and tenant-isolated. This is the main new risk.

## Data sources and gotchas

**`WTF/Account/<ACCT>/SavedVariables/TradeSkillMaster.lua`** (uploaded)
- Written only on /reload or logout. Windows CRLF line endings.
- `r@<realm>@internalData@csvSales`, `csvBuys`, `csvExpired`, `csvCancelled`, `csvExpense` and `csvIncome` are CSV strings inside Lua strings, with escaped `\n`.
- `csvSales.price` is **per unit, after the 5% cut**. Source is `Auction` or `Vendor`; ignore `Vendor` for flips.
- **`csvBuys` is unreliable for commodities.** Lots are double-counted or split inconsistently: a 453 buy was logged as 906, and a 5,351 buy showed up as 351 + 5,000 at a different price. Use it only as reconciliation suggestions.
- Item names: `TSMItemInfoDB.names` and `.itemStrings` are parallel arrays, chunked into strings and joined with `\x02`. Sale-hint keys `"Name\x01i:123\x01..."` are a fallback.
- Inventory comes from `s@<char - faction - realm>@internalData@{bag,bank,reagentBank,auction,mail}Quantity` plus `g@...warbankQuantity`. Known issue: Shredded Glimmerline showed 0 while 287 were held or listed. Investigate before trusting inventory counts.
- Timestamps are UTC epoch.
- **The file is cumulative.** Every upload re-sends all history, so ingest must be idempotent.
- **PCRE limits.** The Python regex `(?:[^"\\]|\\.)*` on multi-hundred-KB strings can hit PHP's `pcre.backtrack_limit` / JIT stack limit. Use possessive quantifiers or a manual scanner, and test with real-size and adversarial inputs.

**Blizzard Game Data API** (fetched by the server)
- OAuth client-credentials (free key from the Blizzard developer portal; I can't create it). Commodities: `/data/wow/auctions/commodities`, namespace `dynamic-<region>`. Updates about hourly.
- The response is very large (tens of MB). Download to a temp file and stream-parse (e.g. `halaxa/json-machine`) into per-item aggregates (min unit price, total quantity, auction count). Never load it into memory.
- Verify in Phase 4: `Last-Modified` / `If-Modified-Since` support, the exact response shape, and current rate limits.
- Item names and icons come from the item API, cached in `items`. TSM names from uploads only fill gaps, are sanitized, and never override Blizzard's.

**Source-of-truth rules**
- Buys come from the `trades` table, entered by the user or confirmed from suggestions.
- Sales come from TSM `csvSales`, counted only from an item's first tracked buy onward.

## Architecture

```
repo root
  docker-compose.yml     app (php-fpm), nginx, db (MariaDB), scheduler (schedule:work)
  docker/php, docker/nginx
  scripts/               setup.sh, db-backup.sh, db-restore.sh
  uploader/              Go source for the companion app (built in Docker)
  legacy/                tsm_ledger.py + old compose/Dockerfile (oracle, removed after parity)
  src/                   Laravel app
    app/Services/        TsmParser, LedgerService, PriceFetchService, PriceStatsService,
                         IngestService, ReconcileService, AlertService
    app/Support/         Money, LuaString, ItemName
    app/Policies/        one per owned model
    app/Livewire/        TradesTable, MarketTable (only interactive tables)
    app/Console/         prices:fetch, prices:rollup, prices:prune, alerts:send
    tests/Unit, tests/Feature, tests/fixtures
```

- Scheduler is the in-stack `scheduler` service, not host cron. Every entry uses `onOneServer()` and `withoutOverlapping()`.
- Ingest parses synchronously in the request (target under 3 s for a real file). If p95 is worse, add a `queue` worker service. Don't add `ShouldQueue` jobs without one.
- Nginx: raise `client_max_body_size` for the ingest and upload routes only. `TRUSTED_PROXIES` is the proxy's IP, never `*`.
- `memory_limit = 512M` via `zz-app.ini` like the finance tracker.

## Schema (MariaDB)

**Shared**
```
items(id PK, name, base_name, quality, icon_url, is_commodity, name_source, fetched_at)
price_snapshots(region, taken_at, item_id, floor, quantity, num_auctions)   -- PK (region, taken_at, item_id)
price_daily(region, day, item_id, floor_min, floor_median, qty_avg, auctions_avg, samples)
item_stats(region, item_id, floor, quantity, recent, typical, hist60, sold_per_day_est,
           days_of_data, updated_at)                                         -- recomputed after each fetch
fetch_runs(id, region, started_at, finished_at, status, rows, last_modified, error)
```

**Per user** (every table has `user_id`, FK with cascade delete)
```
users(+ is_admin, timezone, region, target_margin, stale_minutes, market_share_pct, alerts_enabled)
personal_access_tokens        -- Sanctum, ability 'ingest', shown once, revocable, last_used_at
uploads(user_id, source 'manual'|'uploader', client_version, wow_account, file_mtime, received_at,
        bytes, status, error, counts json)
trades(id, user_id, ts, item_id, qty, cost_copper, side 'buy'|'adjust', note,
       source 'manual'|'paste'|'csv'|'tsm', tsm_ref)
tsm_sales(user_id, realm, item_id, qty, unit_price_copper, sold_at, source, dedupe_key)
tsm_expired, tsm_cancelled    -- same idea, for the item timeline
tsm_buy_candidates(user_id, tsm_ref, item_id, qty, cost_copper, at, status 'pending'|'accepted'|'ignored')
inventory(user_id, item_id, qty, taken_at)    -- latest only
watchlist_items(user_id, item_id, group_name, target_buy_copper, target_sell_copper, notes, sort)
alert_states(user_id, item_id, kind, last_state, last_sent_at)
```

Added since (see the phase checklists for detail):
```
-- Phase 13: games and the addon as a second source
tsm_sales, tsm_expired, tsm_cancelled, tsm_buy_candidates
              (+ game 'retail'|'forever'|'classic', origin 'tsm'|'addon', upload_id, account)
trades, uploads (+ game);  inventory (+ game, origin; unique user, game, item)
users(+ enabled_games, game_view)
tsm_spans(user_id, game, account, from, to)    -- PK (user_id, game, account); TSM wins inside it
-- Phase 14: public stats and scoreboard
users(+ scoreboard_opt_in, scoreboard_nickname unique)
user_activity_days(user_id, day)               -- PK both; kept 90 days
scoreboard_entries(user_id, game, period '7d'|'30d'|'all', pnl_copper, sales_copper, units, flips,
                   computed_at)                -- unique (user_id, game, period); rebuilt hourly
```

- **Idempotent ingest.** `dedupe_key` = hash of (item, time, qty, price, source) plus an occurrence index among identical rows in the file. A re-upload inserts nothing new, and two genuinely identical sales both survive.
- **Data minimization.** Don't store buyer or seller names. Store realm only where it's needed.
- **Raw files are never stored.** Parsed in memory, then dropped. The cost: a failed parse can't be replayed, so log structured diagnostics (section sizes, counts, failing section) instead.
- Corrections remove stock at average cost, and the user picks the reason each time (decided): `side='adjust'` (the ledger was wrong: no P/L) or `side='writeoff'` (vendored, used, lost: the removed cost is a realized loss). Qty is the positive number removed; adding stock is a buy.
- **Tenant isolation.** Policies on every owned model and explicit `user_id` scoping in Services. A feature test walks every authenticated route with another user's IDs and expects 403/404.
- **Retention.** Hourly `price_snapshots` for all commodities for 30 days, then rolled up to `price_daily` and pruned. Tune after measuring row counts.

## Derived price metrics (replace TSM AppData fields)

| UI label | Derived from | Notes |
|---|---|---|
| Floor | latest snapshot min unit price | exact |
| Recent | median floor, last 24 h | replaces `marketValueRecent` |
| Typical | median of daily floors, last 30 d | replaces `regionSale` |
| 60d hist | mean of daily floors, last 60 d | needs 60 d of history |
| Est. sold/day | sum of hourly quantity drops, net of relists | estimate only. Cancels and expiries also reduce supply. |

- Every derived field carries `days_of_data`. Below the threshold the UI shows "collecting data (day N/14)" instead of a number. Scan, falling-knife and days-to-sell are disabled for items below it.
- **Calibration (dev only, never deployed):** a script compares derived metrics to my local `AppData.lua` using the legacy Python parser, so I can tune formulas and thresholds without redistributing TSM data.

## Screens

Dense table-first layout on a dark theme, readable at phone width (tables scroll inside their container). The header shows **TSM saved HH:MM (age)**, **Prices HH:MM (age)**, and an Upload button. A stale warning appears when the last TSM save is older than the user's `stale_minutes`.

1. **Dashboard `/`**: tiles (realized P/L net of cut, stock at cost, stock at floor net of cut, unrealized P/L, own-stock sales); a compact positions table with a status chip (in profit / under water / below avg / above avg); a concentration warning above 40% of stock at cost; the last 20 buys and sales merged.
2. **Positions `/positions`**: held, avg, break-even, target sell, floor, recent, typical, 60d hist, floor/avg, est. sold/day, **days to sell** (held ÷ (est. sold/day × share)), value at floor, unrealized P/L. Sortable, with a closed-positions toggle.
3. **Item detail `/item/{id}`**: rank variants, market numbers, a timeline of buys, sales, expirations and cancellations, a chart of my buy and sell prices over floor/typical/hist lines from snapshots, and running qty and avg cost.
4. **Trades `/trades`** (Livewire): filters, inline edit and delete, an Add buy form (item autocomplete, total cost typed as `2,730g 82s`, live per-unit price), a paste parser for `You won an auction for [Practically Pork]x5351 for 2,730g 82s`, and CSV import and export.
5. **Reconcile `/reconcile`**: `tsm_buy_candidates` not matched to a trade within ±10 minutes. Accept, edit-then-accept, or ignore (remembered by `tsm_ref`). Flag a TSM qty that is exactly twice a nearby trade's qty as a likely double count.
6. **Market `/market`** (Livewire): watchlist with targets, notes, groups and drag reorder. Highlight when floor ≤ target buy or ≥ target sell. Falling-knife flag from derived metrics.
7. **Scan `/scan`**: filters for min item ID, min est. sold/day, max floor/typical and top N, with add-to-watchlist.
8. **Setup `/setup`**: token creation, uploader download and install steps, and a live "first upload received" check.
9. **Uploads `/uploads`**: history with status, counts and errors per upload.
10. **Settings `/settings`**: timezone, target margin, stale minutes, market share, alert preferences, API tokens, export data, delete account.
11. **Admin `/admin`**: users, uploads and failures, Blizzard fetch health, impersonation.
12. **Demo**: a seeded read-only demo user, viewable through a session toggle so every screen is reused. Plus About, Privacy and Terms pages with a "not affiliated with Blizzard or TradeSkillMaster" notice.
13. **Game switch** (Phase 13): the sidebar picks Retail, Forever, Classic or All (two or more enabled games, set in Settings); the dashboard in All is the combined view. Uploads gains a game picker and Undo for the newest upload.
14. **Stats `/stats`** and **Scoreboard `/scoreboard`** (Phase 14, public): anonymous site-wide counts, and an opt-in board of nicknames by realized P/L, sales or units over 7d / 30d / all, per game. The opt-in box is on Profile.

## Companion uploader (Go)

- Single static binary for Windows and macOS (cross-compiled in Docker). No runtime to install.
- Finds the WoW folder from the default paths, or takes a config path. Reads `WTF/Account/*/SavedVariables/TradeSkillMaster.lua` and nothing else. Writes nothing to the WoW folder (Phase 11 adds one opt-in exception, the addon's `Data.lua`).
- Watches mtime (poll about every 30 s). When it changes, waits for the size to stabilize, gzips, and POSTs with `Authorization: Bearer <token>` plus headers for file mtime, client version and WoW account. Retries with backoff, and skips when the hash is unchanged.
- Subcommands: `setup` (server URL, token), `once`, `run`, `install` / `uninstall` (Windows Startup entry or scheduled task, macOS LaunchAgent), `status`.
- The server replies with the minimum supported client version and a human-readable status.
- Plain-text log file only. No tray UI in v1. Unsigned builds trigger SmartScreen and Gatekeeper warnings, so document it in Setup.

## Phases

### Phase 0: Scaffold and conventions
- [x] Move `tsm_ledger.py`, old `compose.yaml`, `Dockerfile`, `.env*` and `data/` into `legacy/` (oracle stays runnable; `docker compose run --rm tsm ledger` from `legacy/` reproduces the known answers below).
- [x] Docker stack cloned from the finance tracker: php-fpm image, entrypoint (composer install, migrate, optimize), nginx, MariaDB 11.8 with healthcheck, scheduler service.
- [x] `src/`: Laravel 13, Breeze (email verification), Livewire 4, Tailwind v3, Vite, Chart.js (npm, no CDN), Sanctum, Pint, PHPUnit on SQLite `:memory:` with the `tests/bootstrap.php` env-forcing trick. Alpine comes from Livewire; no fonts or scripts from third parties.
- [x] `.env.example` (production block uses `wowledger.joshespi.com`), `scripts/setup.sh`, `scripts/db-backup.sh`, `scripts/db-restore.sh`, project `CLAUDE.md` and `README.md`.
- [ ] Rename the working folder to `wow-flip-ledger` (do it between sessions).
- [x] `docker compose up -d --build` serves the Breeze login at http://localhost:8087. 25 tests pass, Pint clean, and a test run adds no rows to MariaDB.

Carried forward:
- Tailwind v3 pulls in 3 moderate `npm audit` advisories (build-time PostCSS chain only, nothing ships to the browser). The fix is the Tailwind v4 migration. Revisit before launch.
- Phase 2: add the larger body-size `location` for the ingest and manual-upload paths in `docker/nginx/default.conf.template` and keep it in sync with the real route paths.
- Phase 10: enable and tune OPcache (PHP 8.5 compiles it in, but the image doesn't configure it).

### Phase 1: Domain core in PHP (no UI)
- [x] `Money` (`fmt`, `parseGold`: "5031.74", "5031g 74s 2c", "2,730g 82s"), `LuaString`, and `ItemName`. Half-to-even rounding to match Python.
- [x] `TsmParser`: names, csv sections, inventory. A line scanner with anchored, non-nested patterns (no regex over whole string values), capped at 48 MB, 1M lines and 300k rows. Limits are constructor args. Also `TradesCsvParser` (needed by the parity test, reused in Phase 6).
- [x] `LedgerService`: weighted average, own-stock split, the same integer math as Python (`intdiv`, same ordering with buys before sells). Malformed sale rows are skipped instead of crashing.
- [x] Fixtures: `tests/fixtures/tsm/` is the real file trimmed to the items in `trades.csv` and the watchlist, one realm, names anonymized (scanned for leaks). Pinned to the 2026-10-25 12:13 save with `make-tsm-fixture.php ... [until]`, since the live file keeps moving.
- [x] **Parity test**: `legacy/dump_golden.py` (imports `tsm_ledger.py` untouched) writes `tests/fixtures/golden/*.golden.json`. PHP matches row for row, and also matched Python on the full real 2 MB file (names, inventory, log counts, trades, ledger) as a one-off check, parsing in 50 ms.
- [x] Known-answer tests, using `legacy/data/trades.csv` as of this plan:
  - Arcane Wyrmfish realized **7,203g 07s** (1,060 bought for 40,891g 25s)
  - Sin'dorei Swarmer realized **−1,488g 16s 20c**; 424 held at **20s**
  - Practically Pork 15,402 held, avg **50s 79c**, break-even **53s 46c**
  - Shredded Glimmerline: 42 sold, **+66g 89s 14c**
  - Shimmersiren: 1,000 held at **30g 10s**
- [x] `Money` round-trips, including negatives and values under 1g. `parseGold` accepts a leading minus on the unit form, an extension the Python tool lacks (the golden cases don't cover it).
- [x] Adversarial parser tests: multi-MB backslash, quote, decimal-escape and comma floods, unterminated and non-string entries, truncated file, and size, line and row caps. Each finishes in under 3 s or refuses cleanly.
- [ ] Decide when to remove `legacy/` now that parity passes. The goldens make it optional for tests; it is still handy for the dev-only AppData calibration in Phase 4.

### Phase 2: Accounts and ingest
- [x] Breeze signup with email verification and login throttling (already in), plus per-user timezone (browser-detected at signup via a hidden field, editable in `/settings`) and region (US only, from `config/ledger.php`). Signups limited to 10/hour/IP.
- [x] Sanctum tokens in `/settings`: create (shown once, never stored in the session), list with `last_used_at`, revoke. Ability `ingest` only, `wfl_` prefix, max 10 per user. Deleting an account now also deletes its tokens (Sanctum tokens have no FK, so they would have been orphaned).
- [x] `POST /api/v1/ingest` (raw or gzipped body, or multipart `file`; headers `X-File-Mtime`, `X-Client-Version`, `X-Wow-Account`; 426 below `ledger.min_client_version`) and `/uploads` (manual upload page plus history), both through `IngestService`. Gzip is detected by magic bytes and decoded with a size cap (zip-bomb safe).
- [x] Idempotent writes via INSERT IGNORE on `(user_id, dedupe_key)` (row hash plus occurrence index): `tsm_sales`, `tsm_expired`, `tsm_cancelled`, `tsm_buy_candidates` (Auction buys only), `inventory` (latest snapshot; an older file or one with no inventory never overwrites), and `items` gap names (only ids the upload references, sanitized, never overriding an existing name). Pets and out-of-range rows are skipped and counted. `uploads` rows record status, counts and errors; the raw file is never kept.
- [x] Limits: per-IP budget before auth (global `LimitIngestByIp`, since Laravel always sorts auth ahead of route-level throttle), per-token 30/h, manual uploads 20/h/user, nginx 50 MB body cap on `/api/v1/ingest` and `/uploads` only (2 MB elsewhere, verified live), PHP `post_max_size`/`upload_max_filesize` 50M.
- [x] Tests: re-upload inserts nothing, two identical sales both survive and stay stable, malformed file leaves data untouched, gzip bomb refused, another user's token only touches that user's data, older file doesn't overwrite newer inventory, throttles, version gate, account deletion.
- [x] Tenant-isolation route walker (`TenantIsolationTest`): fails on any new public route not on its allow-list, any authenticated route that doesn't refuse guests, and any authenticated route parameter without an ownership fixture.
- [x] Measured on the real 1.9 MB file through nginx: parse 45-60 ms, whole ingest 170-280 ms with 22 queries (3,580 sales, 423 expired, 44 cancelled, 1,140 buy candidates, 314 inventory items, 1,134 names). **Decision: synchronous, no queue worker.** Request wall time on this Windows dev setup is dominated by cold framework boot over the bind mount (a trivial `GET /up` swings 0.2-2.8 s), not by ingest.

Carried forward:
- Phase 10: on a native Linux host, set `opcache.validate_timestamps=0` (with `optimize` already run by the production entrypoint) and re-measure; the dev numbers above include bind-mount boot cost.
- Phase 3: the uploader must send the full `id|wfl_…` token string, and handle 422 (bad file), 426 (update needed), 429 (back off using `Retry-After`).
- Phase 6: `tsm_buy_candidates.status` is `pending` until reconcile exists. Deleting a user cascades every per-user table except Sanctum tokens, which the `User` model deletes explicitly.
- `uploads.wow_account` is stored only if the uploader sends a clean label; the real account folder name looks like a login id, so the uploader should let the user pick a label instead of sending it.

### Phase 3: Companion uploader
- [x] Go module in `uploader/` (standard library only, no third-party dependencies), built in Docker by `scripts/build-uploader.sh` for windows/amd64, darwin/amd64, darwin/arm64 (plus linux/amd64 for Wine/Proton), with `SHA256SUMS`. Builds are reproducible (verified: two builds, identical checksums). `scripts/uploader.sh` runs any Go command in the toolchain image.
- [x] WoW path detection (Windows and macOS defaults, `--wow` override, accepts the folder or its `_retail_` child), mtime polling every 30 s, size-and-mtime stable wait before reading (and a re-check after), gzip, POST, per-file exponential backoff (5 s to 15 min, honors `Retry-After`), skip by mtime then content hash, remembered across restarts in `state.json`. Rejected content (422/413) isn't retried until the file changes; fatal errors (401/403/426) wait an hour and say what to fix.
- [x] `setup` (flags or prompts; verifies via ping and refuses a token the server rejects, saves with a warning if the server is unreachable), `status`, `once [--force]`, `run`, `install`, `uninstall`, `version`. Exit codes: 0 ok, 1 failed/retry, 2 update needed, 3 token rejected, 64 usage, 78 not set up. Windows start-at-login is a hidden-window `.vbs` in the Startup folder; macOS is a LaunchAgent (plist and `launchctl bootstrap`). Neither needs admin rights.
- [x] Version handshake: `GET /api/v1/ping` (token, ability, version gate, last upload) added server-side with tests; ingest and ping both return `min_client_version` and 426 for older clients.
- [x] Guarantees (tested): reads only `WTF/Account/<account>/SavedVariables/TradeSkillMaster.lua`, ignores symlinks and the account-wide `SavedVariables` folder, never writes under the WoW folder (code audited: the only writes are its own config, state and log files and the startup entry; Phase 11 adds the opt-in addon `Data.lua`, covered by a test), sends `acct-<hash>` labels instead of the account folder name, masks the token in all output, never follows redirects with the token, requires https except for localhost.
- [x] Tests: 63 Go tests across 8 packages (path detection, stable-size wait, backoff, client status mapping, state, config, log rotation, autostart contents, end-to-end CLI against a stub server) and 5 new PHP ping tests (PHP suite now 185 tests). End-to-end on this Windows machine with the real `.exe`: auto-detected the real WoW folder, `setup` verified the token, `once` uploaded the real file (and a second `once` did nothing), the watch loop uploaded once on start, ignored a touched mtime with identical content, uploaded once on a content change; a revoked token gave exit 3 from `once` and `status`; install/uninstall round trip against a temp Startup folder.
- [x] Decided: the app serves the binaries itself (`/download` plus checksums), built in Phase 9 with the setup wizard. Until then they are build output in `uploader/dist/` (git-ignored).

Not verified: the macOS LaunchAgent install and a real Windows login (only generated file contents and the temp-folder round trip were tested), and SmartScreen/Gatekeeper behavior on a fresh machine.

Carried forward:
- Phase 9 (`/setup` wizard): show the exact `setup` command with the server address filled in, the SmartScreen/Gatekeeper notes from `uploader/README.md`, and the `SHA256SUMS`.
- Code signing and a tray UI stay out of scope for v1.

### Phase 4: Blizzard prices
Built without credentials (they arrive later); everything below is tested against a faked Blizzard API and a local mock server, **not yet against the real one**.

- [x] `BlizzardClient`: OAuth client-credentials token (HTTP Basic, cached in the app cache until 5 min before expiry, so it is shared across processes: 2 token requests for ~10 commands), refreshed once on a 401; commodities downloaded to a temp file with `sink` and `If-Modified-Since`; item lookup; region allow-list; errors never contain credentials or tokens. Config in `config/services.php` (`BLIZZARD_CLIENT_ID`, `_SECRET`, `_REGION`, optional `_TOKEN_URL`, `_API_URL`).
- [x] `prices:fetch` (`PriceFetchService`): stream-parses (`halaxa/json-machine`) into one aggregate per item (min unit price, total quantity, auction count), then writes `price_snapshots` and registers items in one transaction. A 250,000-auction, 21 MB file parses in 1.8 s with flat memory. All-or-nothing: truncated, empty or malformed responses, 5xx and 429 become a `failed` run and leave the last good data untouched. Snapshots are stamped with Blizzard's `Last-Modified` and deduped on it, so a server that ignores `If-Modified-Since` can't create duplicates. Every attempt is a `fetch_runs` row (`ok` / `unchanged` / `failed`); runs left `running` by a crash are failed on the next fetch. Unexpected errors are logged and shown to users as a generic message.
- [x] `items`: Blizzard snapshots create unnamed commodity rows (`name` is now nullable, `name_source = none`); TSM uploads fill null names; `prices:names` (every 15 min, 300 per run, 100 ms apart, nulls first, one lookup per item, 404s remembered via `fetched_at`, stops early on server errors) replaces TSM names with Blizzard's. UI must show "Item #id" while a name is missing. Icons are deferred: they need a second request per item (media endpoint), so add them only if a screen wants them.
- [x] `prices:rollup` (`PriceRollup`: today and yesterday after each fetch, idempotent, median in PHP) and `prices:prune` (`PricePruner`: rolls up any day it is about to delete, then removes snapshots older than `snapshot_days` = 30, daily rows older than 400 d and fetch runs older than 30 d). Scheduled in `bootstrap/app.php`: fetch hourly at :05 and names every 15 min (both skipped until credentials exist), prune daily 03:30; all `onOneServer()` and `withoutOverlapping()` with an expiry.
- [x] `PriceStatsService` recomputes `item_stats` after each fetch from the latest snapshot: `recent` (24 h median), `typical` (30 d median of daily medians), `hist60` (60 d mean), `sold_per_day_est` (hourly supply drops over 7 d, per day covered, never scaled up for under a day of data), `days_of_data`. Windows are anchored at the latest snapshot, not the clock. Items missing from the latest snapshot lose their row. 225 ms for 3,000 items x 4 snapshots; the "6 s" seen from the shell was PHP boot over the Windows bind mount.
- [x] Verified on the real MariaDB (not just SQLite) with a local mock Blizzard server (3,000 items, ~45,000 auctions, honors `If-Modified-Since`): probe, four hourly snapshots, a 304 repeat, names (39 named, 1 not found), rollup, stats and a zero-retention prune (12,000 snapshots deleted, 3,000 daily rows kept). All mock rows removed afterwards.
- [x] Tests: 67 new PHP tests (client, parser incl. malformed bodies and the streaming-memory check, fetch/dedupe/conditional requests/failure handling, rollup incl. chunk boundaries, stats on fixed dates, prune, names, commands, schedule, probe). Suite: 252 tests.
- [x] `prices:probe` (no database writes): the verification checklist as a command. Token, commodities download (size, time, interesting headers), response shape (count, first auction, parsed aggregates), `If-Modified-Since` (304 or "NOT honored" with advice), item lookup.
- [x] `prices:calibrate` + `legacy/dump_appdata.py` (dev only; registered only in `local`/`testing`): dumps TSM's AppData for the 10,190 commodity ids on the dev machine and compares our derived metrics to it (ratio quartiles, share within 20%, rank correlation). Verified the dump on the real file (e.g. Arcane Wyrmfish: floor 455,100, region sale 449,600, ~5,300 sold/day).
- [ ] **Verify against the real API (needs credentials):** put `BLIZZARD_CLIENT_ID` and `BLIZZARD_CLIENT_SECRET` in `.env`, then `docker compose exec app php artisan prices:probe`. It confirms the token endpoint, the commodities response shape and `Last-Modified`, whether `If-Modified-Since` returns 304 (if yes, change `hourlyAt(5)` to every 10 minutes), the real file size and download time, and item lookups. Then `prices:fetch` once and let the scheduler run. Facts already confirmed from Blizzard's forums: limits of 100 requests/second and 36,000/hour per client. Unconfirmed until the probe: the exact response shape and the conditional-request behavior (the code is tolerant of both outcomes).
- [ ] **Calibrate (needs a few weeks of snapshots):** run `prices:calibrate` against a fresh `dump_appdata.py` dump, tune formulas and thresholds (`ledger.prices.min_days`, sold window, falling-knife and days-to-sell cut-offs), and record the findings here before building Scan.

Carried forward:
- [x] Phase 5 header "Prices HH:MM (age)": use the newest `fetch_runs` row with status `ok` or `unchanged` (`finished_at`) and `last_modified` for Blizzard's own age; flag stale when the newest successful run is old or the latest is `failed`. (Done in the Phase 5 cleanup; stale after `ledger.prices.stale_hours` = 3.)
- Derived metrics are shown only when `item_stats.days_of_data >= config('ledger.prices.min_days')` (14); otherwise "collecting data (day N/14)".
- Prices and names are shared across users; the region comes from the user's account (`users.region`, only `us` today).

### Phase 5: Dashboard and Positions
- [x] Layout: **light / system / dark** theme with a three-way toggle (decided with the user; replaces "dark only"). Class-based Tailwind dark mode, a pre-paint script so there is no flash, preference in `localStorage`. Dark variants added to every Breeze view. Money component with g/s/c colors, header freshness strip with the stale warning (per-user `stale_minutes`).
- [x] **Trades table + CSV import** pulled forward from Phase 6 (decided with the user) so the dashboard has real data: `trades` migration, `TradesImportService` (all-or-nothing, idempotent by `import_key`), `/trades/import` page, `php artisan trades:import <file> --user=<email|id>`. Phase 6 adds the full trades UI on top.
- [x] Dashboard (tiles, concentration warning, top positions, recent activity) and Positions (all columns, click-to-sort, closed toggle) on `LedgerService` plus `item_stats`, via `PositionsService`. Settings gained target margin, market share and stale minutes.
- [x] "Collecting data" states: derived metrics, chips and days-to-sell stay hidden until `days_of_data >= ledger.prices.min_days`; the exact floor, cost and P/L always show.
- [x] Feature tests per screen as the user and as a different user (313 tests in all). Dev check: loaded the anonymized fixture into the seeded login with fake prices; verified dark, light and phone width in the browser.

Carried forward:
- [x] `/` replaced with our own landing page (light and dark, not-affiliated notice), our logo and favicon (balance-and-coin artwork; `favicon.ico`, `logo.png`, `apple-touch-icon.png`), per-page titles, and themed error pages (403/404/419/429/500/503). Phase 9's About, Privacy and Terms pages still to do. The positions table got keyboard-accessible sort buttons with `aria-sort` and a sticky header and Item column; the trades import gained a downloadable template.
- Phase 10 CSP must allow the one inline theme script (hash or nonce). Done: nonce.
- Dev database now holds fixture data for `test@example.com` and 6 fake `item_stats` rows; the first real Blizzard fetch replaces the prices on its own.

### Phase 6: Trades and Reconcile
- [x] Trades table (Livewire `Trades`, `/trades`), add form with autocomplete and live per-unit price, edit (loads the row into the form) and delete, item filter, pagination. Writes go through `TradesService`, scoped to the user.
- [x] Paste parser (`TradesPasteParser`) with tests for the chat line format, comma thousands, missing s/c and item links. Pasted lines are stamped with the current time and are not deduplicated (re-pasting adds them again).
- [x] CSV import (Phase 5) and export (`/trades/export`, same format as import; notes starting `= + - @` get a leading `'`). Hand-entered trades have no `import_key`, so re-importing an export duplicates them. No preview/map step.
- [x] Reconcile screen (`/reconcile`, Livewire `Reconcile`, `ReconcileService`): pending `tsm_buy_candidates` with no same-item, same-qty buy within ±10 min; qty exactly twice a nearby buy is flagged "Likely double count". Accept, edit-then-accept or ignore (the candidate's status is the memory, so re-uploads keep it).
- [x] Vendored (2026-11-08): "Vendored" on a Reconcile row accepts the buy and books the same quantity as sold to a vendor (`side='vendor'`, a third correction; `cost_copper` holds the total gold received, so P/L = proceeds − average cost, prorated if less was held). The price is entered per unit and prefilled from the item's last vendoring. Reconcile shows units and gold vendored so far; the item page, trades list and `corrections.csv` (`proceeds_copper`) show it. "Vendored selected" does the same for ticked rows at each item's last vendor price, skipping items never vendored. Not on the item page's Correct holdings form.

### Phase 7: Market and Scan
- [x] Watchlist CRUD (`watchlist_items`, `WatchlistService`, Livewire `Market` at `/market`): per-unit buy/sell targets with row highlight and chips, groups, notes, reorder with up/down buttons within a group (not drag; keyboard-friendly).
- [x] Falling-knife flag (`FallingKnife`, unit tested): floor <= `ledger.market.knife_ratio` (0.8, uncalibrated placeholder) x typical, and floor < recent < typical. False until the item has `min_days` of history.
- [x] Scan (`ScanService`, Livewire `Scan` at `/scan`): items with enough history, filters for % under typical, min sold/day, min item ID and top N (default 50, max 200), cheapest first, Watch button.

### Phase 8: Item detail
- [x] Item page `/items/{item}` (`ItemController`, `ItemDetailService`): timeline of buys, sales, expired and cancelled (newest 100), with profit and held/avg after each buy and sale; summary tiles with gated market numbers; 404 when the user has no history and the item has no prices. Item names link to it from positions, dashboard, trades, market and scan. Rank variants are separate item ids, so each has its own page.
- [x] Price chart (Chart.js, lazy-loaded chunk, `itemChart` in `app.js`): last 90 days of `price_daily` floors (median and low) plus the latest snapshot, typical and 60d lines when gated in, my average cost as a step line, my buys and sales (after cut) as points. Hourly `price_snapshots` are not plotted, only the daily rollup.
- [x] Running position: replay matches `LedgerService` (tested against `PositionsService`).
- [x] Correct holdings (Livewire `CorrectHoldings` at the foot of the item page, `#correct`; "Correct" link on each held Positions row): set held to N (default 0, or the TSM stock when a snapshot has less), choose "the ledger was wrong" or "written off", optional note. `TradesService::correctHeld()` recomputes held server-side (`PositionsService::held()`) and writes one correction for the difference, stamped now. Corrections are listed with Undo there and on `/trades` (no edit), shown in the item timeline, and exported as `corrections.csv`.

### Phase 9: Onboarding, privacy, alerts, admin
- [x] `/setup` wizard (`SetupController`, login-gated): download per platform with the visitor's OS suggested, SHA-256 and size, SmartScreen/Gatekeeper notes, a token form that shows the token once with the exact `setup --server ... --token ...` command filled in, and a Livewire `SetupProgress` that polls every 3 s until the first successful upload (shows the last rejection while waiting). Linked from the dashboard empty state and the uploads page.
- [x] Uploader downloads from the app (decided; no GitHub release). Built as: `UploaderBuilds` lists `src/storage/app/uploader/` (published by `scripts/build-uploader.sh`; git-ignored), offering only files named in `SHA256SUMS` whose real hash matches; `/download/{platform}` and `/download/SHA256SUMS` stream them, login-gated (so no public throttling; the tenant test skips the shared-param route). The page warns when the served version is below `ledger.min_client_version`, which is still bumped by hand. Original design notes:
  - Serve the `scripts/build-uploader.sh` output (windows/amd64, darwin/amd64, darwin/arm64, linux/amd64) and its `SHA256SUMS` from a `/download` route with explicit file names, never a path taken from the request. Publish them to a directory outside `public/` (for example `storage/app/uploader/`) and stream them from a controller, so the route can pick the right file per platform and the nginx 2 MB body cap and route list are unaffected.
  - Binaries are build output, not committed. Decide how they reach the server: the deploy step runs `scripts/build-uploader.sh` and copies `uploader/dist/` into the storage directory.
  - The page shows the matching download for the visitor's OS (detected, with all platforms listed), its SHA-256, the exact `setup` command with this site's address filled in, and the SmartScreen/Gatekeeper notes from `uploader/README.md`.
  - Gate it behind login (the wizard already is), or leave the downloads public: they contain no secrets, but public downloads would need the same per-IP throttling as other routes. Pick one when building it, and add the routes to `TenantIsolationTest`'s allow-list if public.
  - Keep `ledger.min_client_version` in step with the version in the published build, and show the served version on the page.
  - Test: the route serves only the known files, refuses anything else, reports the right checksums, and 404s cleanly when no build has been published.
- [x] Demo (`php artisan demo:seed`, resets the account; run it on deploy): a shared `is_demo` user with a random thrown-away password on `demo@demo.invalid`, filled from copies of the anonymized fixtures in `src/database/demo/` and a small watchlist. Entered with the landing page's "Try the demo" button (`POST /demo`, guests only, throttled). Read-only twice over: `DemoReadOnly` middleware refuses every non-GET except logout and Livewire updates, and the services call `User::assertWritable()` so Livewire writes fail with a message. Market numbers come from the shared prices, so the demo needs real or fake `item_stats` to look alive. `is_demo` is not mass-assignable.
- [x] Alert emails: `alerts:send` (scheduled at :20, after the :05 fetch) runs once per new `ok` refresh per region (marker in cache; `unchanged`/`failed` runs and stale prices are skipped, `--force` overrides). `AlertService`: opt-in (`users.alerts_enabled` off by default) with three rules, each switchable: watchlist buy target, watchlist sell target, and a held position moving into profit after the cut. `alert_states` stores each (item, rule) on/off; mail goes out on the change to on, never while it stays on, and not again within `ledger.alerts.cooldown_hours` (24) of the last email for that pair; one digest per user per run (cap 50, overflow carries over); states are saved only after the mailer accepted the message, so failures retry. Signed unsubscribe link and RFC 8058 one-click headers (`/alerts/unsubscribe/{user}`, GET confirms, POST acts, CSRF-exempt, no login). Preferences in Settings. Brevo credentials are still the Phase 2 open item; `MAIL_MAILER=log` until then.
- [x] Settings: "Download my data" (`DataExportService`, `/settings/export`, 5 per hour) gives a zip of CSVs (trades in import format, sales, expired, cancelled, buy suggestions, watchlist, uploads, account) with a README; never passwords or tokens, formula-safe. Account deletion was already Breeze's password-confirmed Profile action; now tested to remove every per-user table and token, linked from Settings.
- [x] Admin (`/admin`, 404 for non-admins via `EnsureAdmin`): overview (user and upload counts, price fetch health, latest failed uploads, admin activity), users (search, upload and buy counts), uploads (everyone's, failures filter, stored counts only), price fetches (health per region plus every attempt). Admin is granted only by `php artisan admin:grant <email> [--revoke]` (`is_admin` is not mass-assignable; the demo cannot be one). Impersonation ("View as"): not for admins or the demo, read-only (`ReadOnlySession` + `User::assertWritable()`, data export blocked), red banner with Stop, both ends in `admin_audit_logs` plus the log; stopping re-checks the admin role. Fetch-failure notification to the admin is still Phase 10.
- [x] About, Privacy and Terms (public, `x-public-layout`, footer links on the landing and auth pages). The Privacy and Terms text is a plain-language draft written from what the app actually stores; have it reviewed before opening signup. Contact line appears when `LEDGER_CONTACT_EMAIL` is set.
- [x] Buy calculator (`/calculator`, Livewire `Calculator`): per-item cost (`5g25s55c`, `5g 25s` or `5.2555`) in, listing price for break-even, 5/10/15/25% and the user's own target out, with profit per item; optional quantity adds total outlay and profit per stack. Math is `Money::sellPrice()`, shared with `PositionsService`. Reads nothing, writes nothing.

### Phase 10: Harden and ship
- [ ] Reverse proxy and HTTPS on the chosen hostname, with `TRUSTED_PROXIES` set to that proxy's IP. **App side done, infra still yours:** nginx publishes on `APP_BIND:APP_PORT` (bind `127.0.0.1` behind a same-host proxy), the README lists what the app needs from the proxy (headers, 50 MB upload bodies, `TRUSTED_PROXIES`) and a check that the proxy headers are trusted (the HSTS header appears only when Laravel sees HTTPS). The DNS record, certificate and the proxy itself are deploy-time tasks.
- [x] Production `.env` (block at the top of `.env.example`), forced HTTPS (`ForceHttps`: 301/308 to `APP_URL`, never the request Host, `/up` exempt, off until `LEDGER_FORCE_HTTPS` is set because an untrusted proxy would make it loop; `URL::forceScheme` in production already), secure cookies (`session.secure` defaults to true in production), and security headers (`SecurityHeaders`, global): CSP with a per-request nonce for the one inline theme script (Vite and Livewire use the same nonce), `frame-ancestors 'none'`, nosniff, referrer and permissions policies, COOP, HSTS and `upgrade-insecure-requests` over HTTPS. `unsafe-eval` stays in `script-src` because Alpine's standard build evaluates template expressions (the CSP-safe build forbids them); `style-src-attr 'unsafe-inline'` covers Alpine's inline `style` attributes. Checked in the browser against the demo: dashboard, item chart (Chart.js), Livewire filter and theme all ran with no CSP violations. `/build/` is served by nginx with immutable caching and nosniff; gzip is on.
- [x] Backups: the `backup` compose service dumps daily at 03:10 UTC with the app's DB user (`--single-transaction`), verifies the dump, keeps 14 days, writes `backups/LAST_OK` (mode 600 dumps). **Restore tested** into a throwaway MariaDB container: all 22 tables matched the live row counts (sessions and cache excluded). `scripts/db-restore.sh` itself was not run against the live database. Off-machine copies are the operator's job (README). Admin overview shows the last good dump.
- [x] Fetch-failure notification: `health:check` (every 30 min) mails verified admins when a configured region's price fetch is failing or stale, or backups are stale: once at the start, a reminder every 12 h, and a recovery note. Cache-backed state, quiet for what is not set up, retried if the mail fails.
- [x] Tests green (458), Pint clean, README with the deploy steps (`.env`, proxy requirements, first-run tasks, updating, hardening, backups, monitoring, pre-launch checklist).
- [ ] Soft launch to a few friends before opening signup (human step; checklist in the README).

### Phase 11: In-game addon feed
WoW addons cannot make network requests and read files only at login or `/reload`, so the uploader pulls a per-user feed and writes one Lua data file into a small companion addon. Decision: the uploader may write exactly `Interface/AddOns/WowFlipLedger/Data.lua`, opt-in.
- [x] Server: `GET /api/v1/addon-feed` (`AddonFeedService`, `AddonFeedController`): open positions, watchlist (no notes), active alerts, price freshness, summary. Same auth, `ingest` ability and 426 gate as ingest; own `addon-feed` limiter (60/hour per token) so polling does not use ingest's budget. Derived fields stay null before `min_days`. Tested (shape, gating, tenant isolation, notes absent, 401/403/426/429, cap).
- [x] Uploader 0.2.0: `client.Feed`, `internal/addon` (strict Lua serializer, atomic single-file writer, sync loop every 5 min and after each upload, rewrite only on change or hourly), `addon enable|disable|status`, config `addon_sync` (default off), `status` line. Invariant tested: a full sync changes only `Data.lua`; off means no request and no write; a missing addon folder is never created; symlinked folder, file or temp are refused.
- [x] Rules and docs reworded (CLAUDE.md, this file, uploader README).
- [x] Addon (`addon/WowFlipLedger`): item tooltip lines (`TooltipDataProcessor.AddTooltipPostCall`), `/wfl` panel (positions and watchlist), login alerts announced once per feed, data-age and stale-price warnings, names escaped for chat. `addon/tests/run.sh` renders a feed with the uploader's serializer and loads the addon under Lua 5.1 with stubbed WoW APIs (62 checks); a feed captured from the real API was also rendered and loaded. TOC lists Interface `120007, 120100, 120105` (12.0.7 was the newest live build found, 12.1.x was in test; verify with `/dump (select(4, GetBuildInfo()))`).
- [x] Packaging: `scripts/build-uploader.sh` zips the addon (reproducible) and appends it to the published `SHA256SUMS`; `UploaderBuilds::addon()` lists it only if the hash matches; `/download/addon`; step 4 on `/setup`. Public About/Welcome wording corrected.
- [ ] Checked in game (human step: tooltips, panel, alerts and the Interface number need a WoW client). Not exercised: the built `.exe` against the dev server with a real token.

### Phase 12: Own recorder (no TSM dependency) and CurseForge
The addon records the user's own AH activity into `WowFlipLedgerDB` (account-wide SavedVariables); the uploader sends that file like it sends TSM's. TSM import stays, as backfill and for users who run it.
Decisions:
- **What it records:** commodity buys (hook `C_AuctionHouse.ConfirmCommoditiesPurchase`, quote from `COMMODITY_PRICE_UPDATED`, success on `COMMODITY_PURCHASE_SUCCEEDED`), sales and expiries/cancels from mailbox invoices (collected, so each mail is counted once), and an inventory snapshot per character summed at logout. Sale invoices carry only the item *name*; the id comes from a learned name->id map (filled by our own buys, posts and bags), ambiguous or unknown names stay unresolved and are never uploaded guessed.
- **File format:** one string per record (`kind|time|realm|item|qty|...`), because WoW's serializer then writes one record per line and the server reads it with a linear line scanner, like `TsmParser`. No character names: per-character snapshots are keyed by a salted hash of the GUID. Records are never sent to the server by the addon (addons have no network); the uploader sends the file.
- **Buys** from the addon have exact totals, but still land in `tsm_buy_candidates` (pending, accepted in Reconcile) so nothing changes the ledger silently.
- **TSM + addon overlap:** rows carry `origin` (`tsm` | `addon`). TSM wins where it has data: the span TSM has covered (from its earliest row to the latest file time, or its newest row when the file time is unknown) is kept per user, game and WoW account (`tsm_spans`; TSM's SavedVariables are per account, so TSM on one account must not hide the addon's rows from another; an unknown account, as in manual uploads, matches every account); addon rows inside it are not stored, and a TSM upload that widens it deletes addon rows now inside it. So TSM history is never replaced by a less complete record, and the addon covers the time after TSM stops (or if it was never installed).
- **Games:** `retail` and `forever` (World of Warcraft: Forever, `_forever_`, product `wowf`, launched 2026-11-04) share the addon. Forever is a separate economy and has no Blizzard price API: it gets its own ledger (a `game` dimension on the data) and in-game price scans later. Until the server has that dimension, the uploader does **not** send Forever files (they would mix into the retail ledger).
- **Verified on this machine:** `_forever_` exists with TSM installed; TSM's TOC lists `## Interface: 120105, 50504, 20506, 11509` and it runs there, so Forever's number is probably one of those (retail 12.1.5 is `120105`); Forever's build is `1.60.1.70755`. TSM works there through `C_AuctionHouse` and the same mail functions. **Not verified (needs a logged-in Forever client):** `/dump (select(4, GetBuildInfo()))`, `/dump C_AuctionHouse ~= nil`, and every event/mail behaviour below.
- **CurseForge:** the zip is built by CurseForge's packager from the repo (`.pkgmeta`, `## X-Curse-Project-ID`), and the site keeps serving its own zip from `/download/addon`. Creating the CurseForge project and API token is a human step.
Checklist:
- [x] Addon recorder (`Recorder.lua`, `Inventory.lua`), `/wfl recorder`, 46 checks in `addon/tests/recorder.lua` under the Lua 5.1 harness (stubbed WoW API: logic and load errors only).
- [x] Server: `AddonParser`, `origin` columns, the TSM span (`tsm_spans`), ingest picks the parser by the first line, manual upload and API accept the addon file, site copy and privacy page updated. Tests: `AddonParserTest`, `AddonIngestTest`.
- [x] Uploader 0.3.0: finds and sends `WowFlipLedger.lua` (retail only; `_forever_` is noticed in `status`, never read), tests.
- [x] Packaging: TOC fields, `addon/CHANGELOG.md` (checked by the build), `addon/CURSEFORGE.md` (project text, release steps), `scripts/publish-curseforge.sh` (uploads the same zip the site serves; never run). Left to you: create the CurseForge project, pick a license, add the project id and token.
- [x] In-game check on retail (2026-11-04): addon loads (12.1.5, interface 120105), a commodity buy was recorded with the right total, sale invoices read correctly. Found and fixed: the real file layout (CRLF, no indentation) and sale names with no id (client `GetItemInfoInstant` fallback plus server-side unique-name match). Not yet seen: expired/cancelled mail, bank and warband scanning, the uploader sending the real file.
- [x] In-game check on Forever, partly (2026-11-05): interface `16001` (added to the TOC; 120105 showed "Incompatible"), client 1.60.1 build 70790, `C_AuctionHouse` present, addon loads. Buys, sales, mail and banks not yet exercised there.
- [ ] Forever ledger (game dimension, in-game price scan). Split into Phase 13 below.

## Phase 13: Retail / Forever / Combined views

Decisions (2026-11-05):
- **Switcher:** one global Retail / Forever / Both toggle in the sidebar, stored per user; every screen (dashboard, positions, trades, reconcile, charts) follows it.
- **Look:** Retail keeps the gold accent. Forever gets one extra hue, scoped to game identity only (chart series, chips, a game badge, tile border). Document it as the one exception to the single-accent rule. Never colour-only: badge text and dash style too.
- **Combined screen:** per-game split tiles (Retail, Forever, total); realized P/L chart with a series per game plus a total; one positions table with a game chip per row (items are never merged across games); a Retail vs Forever comparison (margin, sell-through, flips per day).
- **Scope:** two phases.

Phase A, data (nothing to show without it):
- [x] `game` column (`retail` | `forever`, default `retail`) on `tsm_sales`, `tsm_expired`, `tsm_cancelled`, `tsm_buy_candidates`, `inventory` (unique per user, game, item), `trades` and `uploads`; Forever dedupe keys are prefixed `forever:` (retail keys unchanged); TSM span per game and WoW account (`tsm_spans`, owned by `Support\TsmSpan`; log rows carry `account`). `Support\Game`, builder macro `inGame()` (Eloquent forwards it). The unreleased migrations are one file, `2026_11_05_000001_add_games_and_addon_origin`. Not yet per game: `watchlist_items`, `alert_states` (retail-only features until Forever has prices).
- [x] Ingest takes the game from `X-Wow-Game` (API) or a `game` form field (manual upload; default retail); an addon file carries the client's `["interface"]` (addon 0.2.1, see the 2026-11-07 addendum) and is refused when it disagrees. Forever never fills the shared item catalog and never matches sale names against it.
- [x] Uploader 0.4.0 reads the same two files under `_forever_` and sends `X-Wow-Game: forever`; `TestSyncChangesOnlyDataLua` still holds. Left: the addon feed and `addon enable` stay retail.
- [x] `PositionsService`, `ItemDetailService`, `ReconcileService`, `TradesService` take a `$game` (default retail); `ScanService`, the trades list/export and corrections list are retail-only; Forever gets no market stats. Tests: `GameIngestTest`.

Phase B, UI:
- [x] Switcher in the sidebar (`<x-game-switcher>`, `POST /game`; `users.game_view`, session first so demo/impersonation can switch without saving); `<x-game-badge>`, `<x-game-banner>`; Forever uses Tailwind `teal` (a teal rule on cards under `data-game="forever"`, dashed teal chart line). No tailwind config change.
- [x] Dashboard, positions, trades (list, add, paste, CSV import, export), corrections, reconcile and the item page follow the switch (`User::activeGame()`); Market, Scan, Calculator stay Retail with a banner on Forever.
- [x] Combined screen (`CombinedService`, `combined.blade.php`, `combinedChart`): split cards, P/L chart (retail solid gold, Forever dashed teal, total thick), Retail vs Forever table (P/L, positions, units sold, sell-through, flips per day; margin left out, it needs per-sale cost), positions table with game badges. In Both, single-game pages show Retail and say so; `/positions` redirects to the dashboard. Forever rows there are not linked (the item page follows the switch).
- [x] Tests: `GameViewTest` (switch, read-only switch, per-game dashboard and trades, combined split, shared chart axis). Not checked in a browser yet (CSP/console, dark/light look).

Addendum (2026-11-05): Classic and a games setting.
- [x] Third game `classic` (`_classic_`; violet, dotted chart line). One Classic game for now; Era or Anniversary would be more entries in `Support\Game`, a span column pair and a sibling folder in the uploader.
- [x] `users.enabled_games` (Settings, "Games I play"): the switcher, upload picker, combined view and banners only show enabled games; one enabled game hides all of it. Existing users got Retail plus any game they had data in; an upload for a game turns it on; data of a disabled game is kept, only hidden. "Both" is now "All" and needs two or more games.
- [x] Manual upload has a game picker (hidden with one game); uploader 0.4.0 also reads `_classic_`.
- [x] Checked in the built-in browser (demo account): no console or CSP errors, light and dark, mobile drawer, settings page. Not checked: Classic data on a real client.

Addendum (2026-11-05): undo an upload.
- [x] `upload_id` on the four ingest tables; `UploadService::undo()` removes the newest successful upload of a game (older rows have no tag; older uploads can't be undone because newer files repeat their history), refuses when its buys were accepted into trades; optional (default on) clear of that game's inventory; the TSM span is always rebuilt from the TSM rows left. Uploads page button, `UploadUndoTest`. Not checked in a browser yet.

Addendum (2026-11-07): wrong-game uploads. On prod, a Forever `TradeSkillMaster.lua` went in as Retail (the upload form defaults to the sidebar's game), putting Forever AH buys into Retail's Reconcile as "Item #id". The Forever addon had also stopped recording on Nov 6: the installed copy was an older 0.2.0 build without `16001` in its TOC, so Forever skipped it as incompatible.
- [x] Addon 0.2.1: `db()` stamps `interface` (GetBuildInfo's 4th value); version bumped so a stale install is visible; CHANGELOG. Lua harness checks the stamp.
- [x] `AddonParser` reads `interface` into `TsmData::$game` via `Game::fromInterface()` (12xxxx retail, 160xx Forever, other five-digit Classic).
- [x] `UploadGameCheck` (called by `IngestService` after the parse): a stamped addon file uploaded as another game is always refused (form error; 422 for the uploader). For manual uploads only, a TSM or unstamped file is refused when all its realms are in one other game's rows, or when none are in the chosen game and that game has rows; "Upload anyway" (`game_confirmed`) overrides the guess. The uploader is not second-guessed on realms. Tests: `UploadGameCheckTest`, `AddonParserTest`; `UploadUndoTest` now confirms its deliberate misfiles.
- [ ] Prod cleanup (you): undo the misfiled Retail upload on /uploads (or ignore its rows on Reconcile) and upload the file as Forever; install 0.2.1 in `_forever_` and `_retail_`; deploy once with `docker compose up -d --build` (the image's entrypoint changed); the app then publishes the 0.2.1 zip itself.
- [x] The addon zip publishes itself: `addon:package` (`AddonPackager`) runs in the app entrypoint and zips the read-only `addon/` mount into `storage/app/uploader` when the TOC version is new, byte-identical to `scripts/build-uploader.sh` (checked: same SHA-256). A version already published with other contents is refused ("bump the version") and the old zip kept; a missing CHANGELOG section is refused too. Never fatal to startup. `build-uploader.sh` is now needed only for uploader releases. `AddonPackageTest`.

## Phase 14: Public stats and opt-in scoreboard

Decisions (2026-11-06):
- **No more admin.** `/admin` already covers operations. "Are people using it" becomes a public, anonymous `/stats` page.
- **`/stats` is public and counts only:** accounts, active today/7d/30d, and per day (30 days, UTC): active users, sales, buys, uploads. Never gold, names, nicknames, realms, items or prices, never a per-person figure. The demo is not counted; only verified accounts count. The privacy page says so in a "Public stats" section, and the page links there.
- **Active users** come from `user_activity_days` (user, date only), recorded in `RecordActivity::terminate()` for web requests and uploader uploads (not its ping or feed polls), at most one row per user per UTC day (primary key, `insertOrIgnore`), never for guests, the demo or impersonation. Kept 90 days (`stats:prune`, daily). History starts at deploy, and the page says "tracked since".
- **Scoreboard is public and opt-in** (off by default), with a box on Profile: a "Show me on the public scoreboard" checkbox plus a nickname (required to opt in, 3–24 chars, `[A-Za-z0-9 _.-]`, unique case-insensitive, reserved words refused). It shows only the nickname, never the name or email.
- **Ranking:** realized P/L by default, with tabs for sales (net of the AH cut, from `tsm_sales`) and units, rolling 7d / 30d / all time, per game. Flips are shown too. A footnote says P/L uses buy prices each player entered.
- **Computed hourly** (`scoreboard:build`) into `scoreboard_entries` from `PositionsService::forUser()` (`Portfolio::$realizedEvents`). Read time filters `scoreboard_opt_in`, so opt-out and nickname changes are instant. Opt-in rebuilds that user at once, and opt-out deletes their rows.

Checklist:
- [x] Migrations: `user_activity_days` (`user_id` FK cascade, `day` date, primary both); `users.scoreboard_opt_in` (bool, default false) and `users.scoreboard_nickname` (string 24, nullable, unique); `scoreboard_entries` (`user_id` FK cascade, `game`, `period` `7d|30d|all`, `pnl_copper`, `sales_copper`, `units` bigint, `flips` int, `computed_at`; unique `user_id, game, period`). Mirror defaults in `User::$attributes`, casts, `#[Fillable]` as needed.
- [x] `App\Http\Middleware\RecordActivity` (terminate), appended to the web group in `bootstrap/app.php` and on the `api.ingest` route only; `stats:prune` (90 days) scheduled daily with `onOneServer()->withoutOverlapping()`.
- [x] `App\Services\SiteStatsService::build()` (cached 10 min, ints and dates only, `date(col)` grouping that works on SQLite and MariaDB); `StatsController`, `GET /stats` (name `stats`), `pages/stats.blade.php` on `<x-public-layout>` with tiles and a 30-day Chart.js bar chart (new `statsChart` Alpine component in `app.js`, lazy `import('chart.js/auto')` like `rangeChart`, count ticks not gold). Under the title: "Anonymous totals across all players. No names, items or gold amounts." plus a link to `privacy#stats`.
- [x] Profile box `profile/partials/scoreboard-form.blade.php` (`<x-section>`, `<x-saved>`), `PATCH /profile/scoreboard` (auth + verified) → `ProfileController::updateScoreboard` with a `ScoreboardRequest` form request.
- [x] `App\Services\ScoreboardService` (`rebuild()`, `rebuildFor(User)`, `forget(User)`, `board($game, $period, $metric, $limit = 50)`) and `scoreboard:build` (hourly). `ScoreboardController`, `GET /scoreboard` (name `scoreboard`, public, query `game`/`period`/`metric` against allow-lists, defaults retail/7d/pnl), `pages/scoreboard.blade.php`: link tabs, a `.data-table` with `sortableTable` (Rank, Player, Realized P/L `<x-money pnl>`, Sales `<x-money>`, Units, Flips), a "you" chip, "updated" time, the footnote, and a link to Profile.
- [x] Links: "Stats" and "Scoreboard" in `<x-legal-links>` and the sidebar (`layouts/navigation`).
- [x] Privacy page (bump date, anchor ids): **Activity** under What we store; a new **Public stats** section (`id="stats"`); a **Scoreboard** section (`id="scoreboard"`); "Who else sees it" mentions the scoreboard.
- [x] Export: `account.csv` gains `scoreboard_opt_in` and `scoreboard_nickname`, plus a new `activity_days.csv`, and the readme lists it.
- [x] `TenantIsolationTest::PUBLIC_URIS` gains `stats` and `scoreboard`. CLAUDE.md gets a short "Stats and scoreboard" section.
- [x] Tests (611 green, Pint clean, build and MariaDB smoke done; checked in the built-in browser: `/stats` and `/scoreboard` light and dark, chart series switch, sidebar and Profile box as the demo, no console or CSP errors. Not seen: a populated scoreboard in a browser, an opt-in by a real account): `RecordActivityTest`, `StatsPageTest` (no gold in output, demo excluded), `ScoreboardTest` (validation, P/L matches `PositionsService`, 7d window, instant opt-out, no name or email in the HTML, demo never listed, bad params fall back), export test updated. Full suite, Pint, `npm run build`, browser check (light/dark, CSP console), and `scoreboard:build` / `stats:prune` run on MariaDB.

## Phase 15: DRY and polish pass

No behaviour change apart from one visual style for cards, toggles and dashes.

- [x] Backend: `Support\Holding` behind both position replays; `Utc::DAY`/`HOUR`/`daysAgo`; `FetchRun::staleAfter`/`latestOk`/`inRegion`; `User` scopes `demo`/`real`/`admins`; `Trade`/`Upload`/`ItemName` source constants; `Money::unit`; `Bounds::validPrice`/`gold`; `Sql::placeholders`; `Csv::write`/`download`; one item-joined query in the data export; `PricesCommand::REGION_OPTION`.
- [x] Front end: `.card-title`, `.card-head`, `.link`, `.note`, `.empty`, `.dl-label`, `.seg`, `.code-block`, `.field-file`, `.page-title`, `.legal` in `app.css`; `<x-time>` and `<x-num>`; `heading`/`actions` slots on the app layout (item and positions pages keep their `<h1>`); stat tiles, chips and `<x-money>` reused in admin, the freshness bar and the landing page; `indigo-950` in the gold scale; mail and chart colours from the app palette; chart setup shared in `app.js`.
- [x] Tests: `SeedsUserRows`, `CallsApi`, `FakesBlizzard::blizzardUnconfigured`.
- [ ] Uploader, addon and scripts (separate session).
- [ ] Left for later, on purpose: `<x-table>` / `<x-chart-card>` / `<x-field>` wrappers, `MarketStats::numbers()`, a shared Reconcile bulk helper (the write-guard test wants `attempt()` in each action), CSV import through `ItemName::idsByName()`, and the remaining test seed helpers (buys, candidates, `price_daily`, addon record builders).

## Open items (need me before the phase that uses them)

- **Brevo sender** (Phase 2, before real signups): verify a sender on `wowledger.joshespi.com` (`.env.example` assumes `noreply@wowledger.joshespi.com`) and create the SMTP key. The DNS record for the hostname itself is also mine to add.
- **Blizzard API credentials** (Phase 4): I need to create a client at the Blizzard developer portal.
- **Public name** (before launch): "WoW" is Blizzard's mark. Keep the name with a disclaimer, or drop "WoW" before opening signup.
- **Folder rename** (Phase 0): `tsm-ledger` to `wow-flip-ledger`. Renaming the folder mid-session would break paths, so do it between sessions.

## Out of scope (v1)

- Writing to TSM's files, or anything under the WoW folder other than the addon's `Data.lua` (Phase 11, opt-in). In-game features beyond the companion addon.
- Posting or buying automation.
- EU region, Classic, and realm-specific (non-commodity) items.
- A tray UI for the uploader, and code signing.
- Using or redistributing TSM AppData.
- Billing or paid tiers.

## Done when

- A new user can sign up, verify email, install the uploader (or upload by hand), and see their ledger within a minute of their first upload.
- After a /reload in game, the uploader pushes the file and new sales show with no manual steps.
- Prices refresh hourly without intervention, and failures are visible in Admin.
- All known-answer, parity, idempotency and tenant-isolation tests pass.
- One user can never read or write another user's data, by any route.
