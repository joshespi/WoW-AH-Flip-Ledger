# legacy

The original single-user CLI. Kept as the **test oracle** for the PHP port: Phase 1 compares the
PHP ledger against this tool's output, row for row. Remove it once parity tests pass.

Run from this folder (it has its own `.env` with `WOW_DIR` and its own `data/`):

```bash
docker compose run --rm tsm ledger
docker compose run --rm tsm market
docker compose run --rm tsm scan --max-ratio 0.75
```

`data/` (trades.csv, watchlist.txt) is personal and git-ignored.
