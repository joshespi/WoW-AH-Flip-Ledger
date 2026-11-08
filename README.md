# WoW Flip Ledger

Track World of Warcraft auction house flips. Sign up, send your TradeSkillMaster data (companion uploader or manual file upload), and see realized P/L, positions and market prices for commodities (US retail).

Built on [TradeSkillMaster](https://www.tradeskillmaster.com): the sales, expirations and cancellations in the ledger come from the data TSM records, and this project would not exist without it. If you trade on the auction house, use TSM.

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
scripts/             setup, db backup and restore, uploader build
src/                 the Laravel app
legacy/              original Python CLI, kept as a test oracle
uploader/            companion uploader (Go); see uploader/README.md
```

## Deploy (self-hosting)

The same `docker-compose.yml` runs locally and in production; only `.env` differs. The stack serves **plain HTTP** from nginx. You put a TLS-terminating reverse proxy in front of it. The values below are what the app expects; the proxy snippets are examples to adapt, not something this repo has run for you.

You need a Linux host with Docker and Compose, a DNS name pointing at it, and a reverse proxy that can get a certificate for that name.

### 1. Get the code and create `.env`

```bash
git clone <your repo> wow-flip-ledger && cd wow-flip-ledger
scripts/setup.sh --no-start      # creates .env and fills APP_KEY and the database passwords
```

Then edit `.env` (the top of `.env.example` lists every production override):

| Setting | Production value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_DOMAIN`, `APP_URL` | your hostname, and `https://` + hostname |
| `APP_BIND` | `127.0.0.1` (the default) when the proxy runs on the same host, so only the proxy can reach nginx; `0.0.0.0` only for a proxy on another host |
| `TRUSTED_PROXIES` | the proxy's IP/CIDR **as nginx sees it** (never `*`; see step 3) |
| `SESSION_SECURE_COOKIE` | `true` (this is also the default when `APP_ENV=production`) |
| `LEDGER_FORCE_HTTPS` | `true`, after step 3 checks out |
| `LOG_LEVEL` | `warning` |
| `MAIL_*` | your SMTP provider (the example values are Brevo's relay); `MAIL_FROM_ADDRESS` must be a verified sender |
| `BLIZZARD_CLIENT_ID`, `BLIZZARD_CLIENT_SECRET` | a client from develop.battle.net; price jobs stay idle until both are set |
| `LEDGER_CONTACT_EMAIL` | shown on the About, Privacy and Terms pages |

Keep a copy of `.env` somewhere safe and separate from the backups: it holds `APP_KEY`, which the data is not usable without, and the database passwords.

### 2. Start it

```bash
docker compose up -d --build
docker compose logs -f app      # first start: composer install, asset build, migrations, caches
```

With `APP_ENV=production` the entrypoint installs without dev dependencies, runs `migrate --force` and caches config and routes. nginx listens on `APP_BIND:APP_PORT` (default 8087).

### 3. Put the TLS proxy in front

Point the proxy at `127.0.0.1:8087` and pass the original scheme and client IP. Examples:

```caddyfile
# Caddy: certificates and the HTTP to HTTPS redirect are automatic
wowledger.example.com {
    reverse_proxy 127.0.0.1:8087
}
```

```nginx
# nginx on the host (certificate paths from your ACME client)
server {
    listen 443 ssl http2;
    server_name wowledger.example.com;
    ssl_certificate     /etc/letsencrypt/live/wowledger.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/wowledger.example.com/privkey.pem;
    client_max_body_size 50m;                      # the TSM upload paths allow 50 MB
    location / {
        proxy_pass http://127.0.0.1:8087;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;   # replace, never append
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port 443;
    }
}
server { listen 80; server_name wowledger.example.com; return 301 https://$host$request_uri; }
```

Laravel only believes `X-Forwarded-*` from the addresses in `TRUSTED_PROXIES`. Find the address nginx sees for your proxy (for a proxy on the same host this is usually the compose network's gateway: `docker network inspect wow-flip-ledger_default`), put it in `.env`, and restart with `docker compose up -d`.

Check it worked:

```bash
curl -sI https://wowledger.example.com/ | grep -i strict-transport-security
```

That header is only sent when Laravel sees the request as HTTPS, so seeing it means the proxy headers are trusted. Only then set `LEDGER_FORCE_HTTPS=true`: with it on and the proxy untrusted, every request looks like plain HTTP and is redirected forever.

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

- **Copy them off the machine.** A dump on the same disk is not a backup. For example, a nightly `rsync` or `rclone` of `./backups` to storage you control.
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

## Before you open signup

A soft launch with a few friends first is the plan. Checklist:

1. HTTPS works on the real hostname and the HSTS check above passes; `LEDGER_FORCE_HTTPS=true`.
2. Mail sends (register a test account; the verification email arrives).
3. `prices:probe` passes, one `prices:fetch` has run, and the admin overview shows prices as healthy.
4. A backup exists, it has been copied off the machine, and a test restore matched.
5. The Privacy and Terms pages have been read and reviewed by someone who should: they are plain-language drafts written from what the app stores.
6. The public name question in PLAN.md ("WoW" is Blizzard's mark) is settled.
7. Run the test suite once on the deployed code: `docker compose exec app php vendor/bin/phpunit` needs dev dependencies, so do this on a development checkout of the same commit instead.
