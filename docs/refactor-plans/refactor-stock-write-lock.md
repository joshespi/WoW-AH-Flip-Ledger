# Refactor: one per-user lock for stock-checking writes

## Serialize every write that checks held stock

**Why:** `TradesService::addCraft()` now locks the user row (`User::whereKey()->lockForUpdate()`) so two simultaneous crafts can't both claim the same stock. Other writes do the same check-then-write unlocked, so a craft racing one of them can still overdraw.

**Where** (`src/app/Services/`):
- `TradesService::addCraft()`: the only one locked today.
- `TradesService::updateCraftTime()`: transaction plus `heldForCraft`, no user lock.
- `TradesService::updateCorrection()`: locks only its own trade row.
- `TradesService::correctHeld()`: `held()` then `create()`, no transaction.
- `TradesService::update()` / `delete()`: change holdings that a concurrent craft or correction read.
- `IngestService::lock()`: `Cache::lock('ingest:'.$user->id)`. Ingest writes `tsm_sales`, which `PositionsService` reads, so an upload can change holdings mid-craft.

**Proposed approach:**
- Add one helper, e.g. private `TradesService::lockStock(User $user)` that does the user row `lockForUpdate`. It must be called inside a transaction.
- Call it first in every transaction above, and wrap `correctHeld` in a transaction.
- Decide whether manual trade writes should also take the ingest lock, or say in a comment why the row lock is enough.

**Risks / open questions:**
- SQLite (tests) ignores `lockForUpdate`, so tests can't prove the race. Smoke-test on MariaDB.
- Lock order: ingest holds a cache lock and then writes rows. Mixing the cache lock and row locks needs a fixed order to avoid deadlocks.
- `heldForCraft` rebuilds a ledger, so holding the lock longer serializes that user's writes. That is fine at current volume.

**Suggested scope:**
- In: `TradesService` stock-checking writes and the helper. A note or decision on the ingest lock.
- Out: `ReconcileService` and `WatchlistService` locks, and any queue or job changes.
