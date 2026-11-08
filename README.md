# WoW Flip Ledger

Track World of Warcraft auction house flips. Sign up, send your data (companion uploader or manual file upload), and see realized P/L, positions and market prices for commodities (US region).

- **Data:** your TradeSkillMaster `SavedVariables`, or the site's own in-game addon (`addon/`, no TSM needed), which records your buys, sales, expiries, cancels and stock. Where both cover the same time, TSM wins.
- **Games:** Retail, Forever and Classic, each a separate ledger, with an All view. Market prices (Blizzard's AH API) are Retail only.
- **Public pages:** `/stats` (anonymous site-wide counts) and an opt-in `/scoreboard` (nicknames only).

Built on [TradeSkillMaster](https://www.tradeskillmaster.com): TSM's records are the main source of sales, expirations and cancellations, and this project would not exist without it. If you trade on the auction house, use TSM.

Not affiliated with Blizzard Entertainment or TradeSkillMaster.

[PLAN.md](PLAN.md) holds the roadmap and the decisions behind it; [CLAUDE.md](CLAUDE.md) is the working guide for the codebase.

## Run it locally

Everything runs in Docker. There is no local PHP, Node or Go install.

```bash
scripts/setup.sh
```

That creates `.env` with generated secrets and starts the stack. The first start installs dependencies and builds assets, so watch it with `docker compose logs -f app`. The app is at http://localhost:8087.

Mail goes to the container log (`MAIL_MAILER=log`), so verification and reset links appear in `docker compose logs app`.

## Everyday commands

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
scripts/             setup, db backup and restore, uploader and addon build, CurseForge publish
src/                 the Laravel app
addon/               in-game recorder addon (Lua); see addon/CURSEFORGE.md, tests in addon/tests
legacy/              original Python CLI, kept as a test oracle
uploader/            companion uploader (Go); see uploader/README.md
```

## Deploy

The same `docker-compose.yml` runs locally and in production; only `.env` differs. nginx serves plain HTTP on `APP_BIND:APP_PORT` (default `127.0.0.1:8087`); TLS, DNS and the reverse proxy are yours to provide.

### 1. Configure `.env`

```bash
scripts/setup.sh --no-start      # creates .env and fills APP_KEY and the database passwords
```

Then edit `.env` (the top of `.env.example` lists every production override):

| Setting                                        | Production value                                                                                           |
| ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `APP_ENV`                                      | `production`                                                                                               |
| `APP_DEBUG`                                    | `false`                                                                                                    |
| `APP_DOMAIN`, `APP_URL`                        | your hostname, and `https://` + hostname                                                                   |
| `APP_BIND`                                     | `127.0.0.1` (the default) when the proxy runs on the same host; `0.0.0.0` only for a proxy on another host |
| `TRUSTED_PROXIES`                              | the proxy's IP/CIDR **as nginx sees it** (never `*`; see below)                                            |
| `SESSION_SECURE_COOKIE`                        | `true` (this is also the default when `APP_ENV=production`)                                                |
| `LEDGER_FORCE_HTTPS`                           | `true`, once the HSTS check below passes                                                                   |
| `LOG_LEVEL`                                    | `warning`                                                                                                  |
| `MAIL_*`                                       | your SMTP provider (the example values are Brevo's relay); `MAIL_FROM_ADDRESS` must be a verified sender   |
| `BLIZZARD_CLIENT_ID`, `BLIZZARD_CLIENT_SECRET` | a client from develop.battle.net; price jobs stay idle until both are set                                  |
| `LEDGER_CONTACT_EMAIL`                         | shown on the About, Privacy and Terms pages                                                                |

Keep a copy of `.env` somewhere safe and separate from the backups: it holds `APP_KEY`, which the data is not usable without, and the database passwords.

### 2. Start it

```bash
docker compose up -d --build
docker compose logs -f app      # first start: composer install, asset build, migrations, caches
```

With `APP_ENV=production` the entrypoint installs without dev dependencies, runs `migrate --force` and caches config and routes.

### 3. What the app needs from your proxy

- **Headers:** `Host`, `X-Forwarded-For` (set to the client address, not appended), `X-Forwarded-Proto` and `X-Forwarded-Port`. `X-Forwarded-Host` is ignored.
- **Body size:** at least 50 MB for `POST /api/v1/ingest` and `POST /uploads` (TSM files); everything else is capped at 2 MB by our nginx.
- **Trust:** Laravel believes `X-Forwarded-*` only from `TRUSTED_PROXIES`. For a proxy on the same host the address nginx sees is usually the compose network's gateway (`docker network inspect wow-flip-ledger_default`). Restart with `docker compose up -d` after changing it.

Check the trust is right:

```bash
curl -sI https://<your host>/ | grep -i strict-transport-security
```

HSTS is only sent when Laravel sees the request as HTTPS, so seeing it means the proxy headers are trusted. Only then set `LEDGER_FORCE_HTTPS=true`: with it on and the proxy untrusted, every request looks like plain HTTP and is redirected forever.

### 4. First-run tasks

```bash
# make yourself an admin (register an account on the site first)
docker compose exec app php artisan admin:grant you@example.com

# build and publish the companion uploader for the /setup page (needs Docker only)
scripts/build-uploader.sh

# optional read-only demo account from the anonymized sample data
docker compose exec app php artisan demo:seed

# check the Blizzard credentials without writing anything
docker compose exec app php artisan prices:probe
```

Prices are fetched hourly by the `scheduler` container, alerts go out after each refresh, and `health:check` emails admins about failures (below). The in-game addon zip publishes itself: on every start the app zips `addon/` when its TOC version is new (`php artisan addon:package`; a changed addon without a version bump is refused and logged, keeping the old zip). Re-run `scripts/build-uploader.sh` only when you release a new uploader version, and bump `LEDGER_MIN_CLIENT_VERSION` only when old uploaders must be refused.

### 5. Updating

```bash
git pull
docker compose up -d --build
```

The entrypoint reinstalls dependencies, rebuilds assets, migrates and publishes a new addon version. Take a backup first if the release has migrations.

## What is hardened

- **Headers on every response** (`SecurityHeaders` middleware): a Content-Security-Policy that allows only the site's own origin plus a per-request nonce for the one inline theme script, `frame-ancestors 'none'`, `nosniff`, a strict referrer policy, a locked-down permissions policy, and HSTS plus `upgrade-insecure-requests` over HTTPS. Nothing third-party is loaded. `script-src` keeps `unsafe-eval` because Alpine (shipped with Livewire) evaluates the expressions in the templates.
- **Cookies:** `Secure`, `HttpOnly` and `SameSite=Lax` sessions; secure by default in production.
- **Proxy trust:** `TRUSTED_PROXIES` is an explicit list; `X-Forwarded-Host` is never trusted, and `trustHosts()` pins the accepted Host to `APP_URL`.
- **Static assets** under `/build/` are served by nginx with long-lived caching and `nosniff`.
- **Uploads** are parsed in memory and never stored; the database is not published on the host.

## Backups

The `backup` service writes a gzip dump of the database to `./backups` every day at 03:10 UTC, keeps 14 days (`BACKUP_KEEP_DAYS`, `BACKUP_HOUR_UTC`), takes one at start if none is recent, and records the time of the last good one in `backups/LAST_OK`. Dumps are mode 600 and hold every user's data.

- **Copy them off the machine.** A dump on the same disk is not a backup.
- **Check it is working:** the admin overview shows the last good dump, and `health:check` emails the admins when none has completed for 36 hours.
- **Take one now:** `scripts/db-backup.sh`.
- **Restore** (replaces the live database after a confirmation prompt; `--yes` skips it):

```bash
scripts/db-restore.sh backups/<file>.sql.gz
```

- **Test a restore without touching live data,** into a throwaway container, then compare row counts:

```bash
docker run -d --name restore-test -e MARIADB_ROOT_PASSWORD=scratch -e MARIADB_DATABASE=restore_test mariadb:11.8
gunzip -c backups/<file>.sql.gz | docker exec -i restore-test mariadb -uroot -pscratch restore_test
docker exec restore-test mariadb -uroot -pscratch restore_test -e "select count(*) from users"
docker rm -f restore-test
```

Do this after setting up backups and now and then after; a restore you have never run is a guess. A dump of this stack was restored this way during development and every table's row count matched the live database (the sessions and cache tables aside, which change constantly). Backup copies expire with the retention above, which is also how long a deleted account can linger in them.

## Monitoring

`health:check` runs every 30 minutes and emails every verified admin when the Blizzard price fetch is failing or stale, or backups have stopped. It reports a problem when it starts, repeats every 12 hours while it lasts (`LEDGER_HEALTH_REMINDER_HOURS`), and says when it is fixed. It stays quiet about things that are not set up (no Blizzard credentials, no backup service). The same state is on the admin pages: fetch health and every fetch attempt, uploads and failures, and the backup card.

Other scheduled work (all in `bootstrap/app.php`, run by the `scheduler` container): `prices:fetch` at :05 and :35 (Blizzard updates hourly; the second try catches a late update), `prices:names` every 15 minutes, `alerts:send` at :20 and :50, `prices:prune` daily, `scoreboard:build` hourly (the public scoreboard; opt-in and opt-out apply at once regardless), and `stats:prune` daily (drops activity days older than 90, the window the privacy page promises). `/stats` is cached for 10 minutes.
