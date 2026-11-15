# WoW Flip Ledger

Track World of Warcraft auction house flips. Sign up, send your data (companion uploader or manual upload), and see realized P/L, positions and market prices for commodities (US region).

- **Data:** your TradeSkillMaster `SavedVariables`, or the in-game addon (`addon/`, no TSM needed). Where both cover the same time, TSM wins.
- **Games:** Retail, Forever and Classic, each its own ledger, plus an All view. Blizzard AH prices are Retail only.
- **Public pages:** `/stats` (anonymous counts) and an opt-in `/scoreboard` (nicknames only).

Built on [TradeSkillMaster](https://www.tradeskillmaster.com), whose records are the main source of sales, expirations and cancellations. If you trade on the AH, use TSM. Not affiliated with Blizzard Entertainment or TradeSkillMaster.

[PLAN.md](PLAN.md) is the roadmap; [CLAUDE.md](CLAUDE.md) is the codebase guide.

## Run it locally

Everything runs in Docker; no local PHP, Node or Go.

```bash
scripts/setup.sh
```

This creates `.env` with generated secrets and starts the stack (first start installs and builds; watch `docker compose logs -f app`). App: http://localhost:8087. Mail goes to the container log, so verification and reset links show up there.

```bash
docker compose exec app php artisan <command>
docker compose exec app composer <command>
docker compose exec app npm run build
docker compose exec app php vendor/bin/phpunit
docker compose exec app ./vendor/bin/pint
```

## Layout

```
docker-compose.yml   app (php-fpm), scheduler, nginx, db (MariaDB), backup
docker/              image, nginx and backup config
scripts/             setup, backup/restore, uploader and addon build, CurseForge publish
src/                 the Laravel app
addon/               in-game recorder addon (Lua); see addon/CURSEFORGE.md
legacy/              original Python CLI, kept as a test oracle
uploader/            companion uploader (Go); see uploader/README.md
```

## Deploy

The same `docker-compose.yml` serves local and production; only `.env` differs. nginx serves plain HTTP on `APP_BIND:APP_PORT` (default `127.0.0.1:8087`); TLS, DNS and the reverse proxy are yours.

1. **Configure.** `scripts/setup.sh --no-start`, then edit `.env` (the top of `.env.example` lists every production override). Key settings:
   - `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`
   - `APP_DOMAIN`, `APP_URL` (`https://` + hostname)
   - `TRUSTED_PROXIES`: the proxy's IP/CIDR as nginx sees it, never `*`
   - `LEDGER_FORCE_HTTPS=true`, only after the HSTS check below passes
   - `MAIL_*` (SMTP; `MAIL_FROM_ADDRESS` must be a verified sender)
   - `BLIZZARD_CLIENT_ID`, `BLIZZARD_CLIENT_SECRET` (price jobs idle until both are set)
   - `LEDGER_CONTACT_EMAIL` (shown on About, Privacy, Terms)

   Back up `.env` separately from the DB backups: it holds `APP_KEY`, without which the data is unusable.

2. **Start.** `docker compose up -d --build`. In production the entrypoint skips dev dependencies, runs `migrate --force` and caches config and routes.

3. **Proxy.** It must send `Host`, `X-Forwarded-For` (the client address, not appended), `X-Forwarded-Proto` and `X-Forwarded-Port`, and allow bodies of at least 50 MB on `POST /api/v1/ingest` and `POST /uploads` (everything else is capped at 2 MB). Laravel trusts `X-Forwarded-*` only from `TRUSTED_PROXIES`; for a same-host proxy that is usually the compose network gateway (`docker network inspect wow-flip-ledger_default`). Verify:

   ```bash
   curl -sI https://<your host>/ | grep -i strict-transport-security
   ```

   HSTS appears only when Laravel sees HTTPS, so seeing it means the proxy is trusted. With `LEDGER_FORCE_HTTPS` on and the proxy untrusted, every request redirects forever.

4. **First run.**

   ```bash
   docker compose exec app php artisan admin:grant you@example.com   # register on the site first
   scripts/build-uploader.sh                                         # uploader for /setup
   docker compose exec app php artisan demo:seed                     # optional read-only demo
   docker compose exec app php artisan prices:probe                  # check Blizzard credentials
   ```

   The addon zip publishes itself on start when its TOC version is new (a changed addon without a version bump is refused). Re-run `build-uploader.sh` only for a new uploader release; bump `LEDGER_MIN_CLIENT_VERSION` only to refuse old uploaders.

5. **Update.** `git pull && docker compose up -d --build`. Back up first if the release has migrations.

## Hardening

- `SecurityHeaders` sets a self-only CSP (nonce for inline scripts; `unsafe-eval` stays for Alpine), `frame-ancestors 'none'`, `nosniff`, referrer and permissions policies, and HSTS over HTTPS. Nothing third-party loads.
- Sessions are `Secure`, `HttpOnly`, `SameSite=Lax`. `X-Forwarded-Host` is never trusted; `trustHosts()` pins Host to `APP_URL`.
- Uploads are parsed in memory and never stored. The database is not published on the host.

## Backups

The `backup` service dumps the database (gzip, mode 600) daily at 03:10 UTC, keeps 14 days (`BACKUP_KEEP_DAYS`, `BACKUP_HOUR_UTC`), and writes `backups/LAST_OK`. Copy dumps off the machine; the admin overview shows the last good one.

```bash
scripts/db-backup.sh                          # take one now
scripts/db-restore.sh backups/<file>.sql.gz   # replace the live DB (asks first; --yes skips)
```

Test a restore in a throwaway container and compare row counts:

```bash
docker run -d --name restore-test -e MARIADB_ROOT_PASSWORD=scratch -e MARIADB_DATABASE=restore_test mariadb:11.8
gunzip -c backups/<file>.sql.gz | docker exec -i restore-test mariadb -uroot -pscratch restore_test
docker exec restore-test mariadb -uroot -pscratch restore_test -e "select count(*) from users"
docker rm -f restore-test
```

Deleted accounts can linger in backups until retention expires.

## Monitoring

`health:check` (every 30 min) emails verified admins when the price fetch fails or goes stale, or backups stop (36 h). It repeats every 12 h (`LEDGER_HEALTH_REMINDER_HOURS`), reports recovery, and ignores things not set up. The same state is on the admin pages.

Scheduled work lives in `bootstrap/app.php`: `prices:fetch` (:05, :35), `prices:names`, `alerts:send`, `prices:prune`, `scoreboard:build`, `stats:prune`.
