# TSM Ledger: Web UI Plan

Turn `tsm_ledger.py` (CLI) into a local web app for tracking WoW auction house flips. Single user, runs in Docker on the same machine as WoW. Read-only access to WoW files.

## Conventions

- Don't commit. Leave changes uncommitted; I make my own commits. End each phase with a terse commit message and a short summary of what changed and why.
- Keep a checklist per phase and check items off as you go.
- Comments: terse and to the point. No filler, no AI-speak.
- Everything runs in Docker (`docker compose up`). Host is Windows or macOS.
- Never write to anything under the WoW mount.

## Stack

- **Python 3.12, FastAPI, Jinja2, htmx.** Keeps the existing parser. No JS build step.
- **SQLite** at `data/ledger.db` for trades, watchlist and price snapshots.
- **Chart.js**, vendored into `static/` (no CDN at runtime).
- **pytest** for tests.
- The CLI keeps working (`docker compose run --rm tsm ledger` etc.) on top of the same package.

Alternative: Laravel + Livewire, if I'd rather maintain it in PHP. In that case port the parsers to PHP and keep everything else in this plan the same. Ask before choosing this.

## Domain primer

- **Commodities** (mats, fish, ore) trade on one region-wide auction house. Prices are per unit.
- Money is stored as **copper** integers: 1g = 100s = 10,000c. Display as `1,234g 56s 78c`, with g/s/c colored gold/silver/copper.
- The **AH cut** is 5% of the sale price. The deposit is refunded on a sale, so it is not profit.
- **Break-even** = avg cost / 0.95. Default **target margin** is 15%, so the target sell price is avg cost × 1.15 / 0.95.
- **Rank variants** of the same item are separate item IDs, e.g. Void-Tempered Leather 238511 (R1) and 238512 (R2). Group them by name in lists and keep them as separate rows.
- **Cost basis** is weighted average cost, not FIFO.
- **Own stock**: sales beyond the tracked held quantity are gathered or pre-existing stock. Report them separately and keep them out of flip P/L.

## Data sources and gotchas

All parsing is already in `tsm_ledger.py`. Move it into the package as-is and cover it with tests before changing anything.

**`WTF/Account/<ACCT>/SavedVariables/TradeSkillMaster.lua`**
- Written only on /reload or logout. Show its mtime prominently, with a stale warning when it's more than 30 minutes old.
- Windows CRLF line endings.
- `r@<realm>@internalData@csvSales`, `csvBuys`, `csvExpired`, `csvCancelled`, `csvExpense` and `csvIncome` are CSV strings inside Lua strings, with escaped `\n`.
- `csvSales.price` is **per unit, after the 5% cut**. Source is `Auction` or `Vendor`; ignore `Vendor` for flips.
- **`csvBuys` is unreliable for commodities.** Lots are double-counted or split inconsistently: a 453 buy was logged as 906, and a 5,351 buy showed up as 351 + 5,000 at a different price. Never treat it as the source of truth. Use it only as suggestions for reconciliation (Phase 3).
- Item names: `TSMItemInfoDB.names` and `.itemStrings` are parallel arrays, chunked into strings and joined with `\x02`. Sale-hint keys `"Name\x01i:123\x01..."` are a fallback.
- Inventory comes from `s@<char - faction - realm>@internalData@{bag,bank,reagentBank,auction,mail}Quantity` plus `g@...warbankQuantity`. Known issue: Shredded Glimmerline showed 0 while 287 were held or listed. Investigate before trusting inventory counts.
- Timestamps are UTC epoch. Display in `TZ` (America/Denver).

**`Interface/AddOns/TradeSkillMaster_AppHelper/AppData.lua`**
- `LoadData("<KIND>","<realm|US>",[[return {downloadTime=..., fields={...}, data={{...},...}}]])`
- Values are **base-32 ints**. The key is a numeric item ID or a quoted itemString with bonus IDs.
- `regionSoldPerDay` and `regionSalePercent` are stored ×1000.
- The kinds used are COMMODITY_DATA (minBuyout = floor, numAuctions, marketValueRecent), COMMODITY_SCAN_STAT, COMMODITY_HISTORICAL (60-day), REGION_STAT, REGION_HISTORICAL and REGION_SALE (regionSale avg, sold/day).
- The TSM desktop app refreshes it about hourly. There's no history in the file. **The app must snapshot it to build price history** (Phase 5).

**Source-of-truth rules**
- Buys come from the `trades` table, entered by me or confirmed from suggestions.
- Sales come from TSM `csvSales`, counted only from an item's first tracked buy onward.
- Re-parse the files when their mtime changes, and cache by mtime.

## Package layout

```
tsm_ledger/
  __init__.py
  money.py        fmt, parse_gold (accepts "5031.74", "5031g 74s 2c", "2,730g 82s")
  tsm.py          SavedVariables parser
  appdata.py      AppData parser
  ledger.py       weighted-avg ledger, own-stock split
  db.py           sqlite schema + migrations (plain SQL, numbered files)
  service.py      combines sources; what both CLI and web call
  cli.py          current commands
  web/
    app.py        FastAPI routes
    templates/
    static/       htmx, chart.js, css
tests/
  fixtures/       trimmed real files (only relevant items)
data/             mounted: ledger.db, trades.csv (import/export), watchlist.txt (import)
```

## Schema (SQLite)

```
trades(id, ts, item_id, qty, cost_copper, side 'buy'|'adjust', note, source 'manual'|'tsm'|'csv', tsm_ref, created_at)
watchlist(item_id, name, group_name, target_buy_copper NULL, target_sell_copper NULL, notes, sort)
snapshots(taken_at, item_id, floor, num_auctions, recent, market, hist60, region_sale, sold_per_day)  -- PK (taken_at,item_id)
settings(key, value)  -- target_margin, stale_minutes, scan defaults
```

- `side='adjust'` is for write-offs and corrections, e.g. vendored or used stock. Qty can be negative.
- On first run, import `data/trades.csv` and `data/watchlist.txt` if the tables are empty.

## Screens

Use a dense table-first layout on a dark theme, readable at phone width (tables scroll horizontally inside their container). Every page header shows: **TSM saved HH:MM (age)**, **Prices HH:MM (age)**, and a Refresh button that re-reads the files.

### 1. Dashboard `/`
- Tiles: realized P/L (net of cut), stock at cost, stock at current floor (net of cut), unrealized P/L, and own-stock sales.
- A positions table, compact: item, held, avg, break-even, floor, floor vs break-even %, and status chip (in profit / under water / below avg / above avg).
- Concentration warning when one item is more than 40% of stock at cost. Shimmersiren is currently about 65%.
- Recent activity: the last 20 buys and sales, merged.

### 2. Positions `/positions`
- Full columns: held, avg, break-even, target sell, floor, recent, region sale avg, 60d hist, floor/avg, sold/day, **days to sell** (held ÷ (sold/day × assumed share, default 5%)), value at floor, unrealized P/L.
- Sortable, with a toggle to show closed positions.
- Row link goes to Item detail.

### 3. Item detail `/item/{id}`
- Header: name, rank variants (links), current market numbers.
- Timeline: buys (trades), sales, expirations and cancellations from TSM. Expirations matter: the 12.7k Swarmer listed at 50s expired twice before selling at 29s.
- Chart: my buy and sell prices as points, with floor, region sale avg and 60d hist lines drawn from snapshots.
- Running position: qty and avg cost over time.

### 4. Trades `/trades`
- Table with filters (item, date range, source). Edit and delete inline (htmx).
- **Add buy** form: item autocomplete from TSM names (show ID and rank), qty, and total cost typed as `2,730g 82s`. Shows the per-unit price live.
- **Paste parser**: a textarea that accepts lines like `You won an auction for [Practically Pork]x5351 for 2,730g 82s`, with one buy per line. It previews them, then saves.
- Export to CSV.

### 5. Reconcile `/reconcile`
- TSM `csvBuys` rows not matched to a trade within ±10 minutes on the same item.
- For each one: accept as-is, accept with an edited qty/cost, or ignore. Remember ignored items by `tsm_ref` (realm + time + item + qty + price).
- Flag rows where the TSM qty is exactly twice a nearby trade's qty, as a likely double count.

### 6. Market `/market`
- Watchlist table: the same market columns as Positions, plus target buy/sell and notes.
- Add or remove items with autocomplete, drag to reorder, and group headers.
- Highlight a row when the floor is at or below target buy, or at or above target sell.
- **Falling-knife flag**: floor/avg ≤ 0.75 *and* recent < 60d hist × 0.8. Petrified Root (340g → 60g) is the example.

### 7. Scan `/scan`
- Same as the CLI scan, with filters for min item ID, min sold/day, max floor/avg, and top N.
- Show the falling-knife flag on each row, and an "add to watchlist" button.

## Background jobs

- **Snapshot** every 15 minutes, and whenever the AppData mtime changes. Store rows for watchlist items plus anything held or traded. Skip when `downloadTime` hasn't changed.
- No other writers.

## Phases

### Phase 1: Package + tests (no behavior change)
- [ ] Split `tsm_ledger.py` into the package layout. CLI output stays identical.
- [ ] Build fixtures by trimming my real files to the items in `trades.csv`, plus a few watchlist items.
- [ ] Known-answer tests, using `data/trades.csv` as of this plan with the fixture TSM file:
  - Arcane Wyrmfish realized **7,203g 07s** (1,060 bought for 40,891g 25s)
  - Sin'dorei Swarmer realized **−1,488g 16s 20c**; 424 held at **20s**
  - Practically Pork 15,402 held, avg **50s 79c**, break-even **53s 46c**
  - Shredded Glimmerline: 42 sold, **+66g 89s 14c**
  - Shimmersiren: 1,000 held at **30g 10s**
- [ ] `parse_gold` and `fmt` round-trip tests, including negatives and values under 1g.
- [ ] AppData base-32 decode and the ×1000 fields.

### Phase 2: DB + web skeleton + Dashboard + Positions
- [ ] SQLite schema, migrations, and the first-run import of trades.csv and watchlist.txt
- [ ] FastAPI app with a header showing data freshness and Refresh
- [ ] Dashboard and Positions
- [ ] Compose: a `web` service on `127.0.0.1:8080`, keeping the existing `tsm` CLI service. Both mount `./data` and the WoW folder read-only.

### Phase 3: Trades + Reconcile
- [ ] Trades table, add form, inline edit and delete
- [ ] Paste parser (with tests for the chat line format, comma thousands, and missing s/c)
- [ ] Reconcile screen with double-count detection

### Phase 4: Market + Scan
- [ ] Watchlist CRUD, targets, reorder
- [ ] Falling-knife flag (unit tested)
- [ ] Scan screen with add-to-watchlist

### Phase 5: Snapshots + Item detail
- [ ] Snapshot job and its table
- [ ] Item detail timeline and chart

## Out of scope

- Writing to WoW or TSM files, and any in-game addon work.
- Posting or buying automation.
- Multiple users and auth (bind to localhost only).

## Done when

- `docker compose up` serves the app at localhost:8080 within a few seconds of startup.
- After a /reload, Refresh shows new sales without restarting the container.
- All known-answer tests pass, and the CLI output matches what it printed before the refactor.
