# WoW Flip Ledger uploader

Sends your TradeSkillMaster data to WoW Flip Ledger automatically after each `/reload` or logout.

- Reads exactly one file per WoW account: `WTF/Account/<account>/SavedVariables/TradeSkillMaster.lua`.
- Writes nothing under the WoW folder unless you run `addon enable` (below); then it replaces one file, `Interface/AddOns/WowFlipLedger/Data.lua`, and never creates the addon folder. Its own `config.json`, `state.json` and `uploader.log` live in your user config folder (`%APPDATA%\WoWFlipLedger` on Windows, `~/Library/Application Support/WoWFlipLedger` on macOS), plus the start-at-login entry if you ask for it.
- Sends the file gzipped over HTTPS with a token that can only upload. It never sends your account folder name; the site sees a short stable label like `acct-1a2b3c`.
- Skips files that haven't changed (by modification time, then by content hash), and waits for TSM to finish writing before reading.

## Use

1. On the site, open **Settings**, create a token and copy it (it is shown once).
2. Run:

```
wowflipledger-uploader setup
wowflipledger-uploader once        # upload now
wowflipledger-uploader install     # start at login (Windows and macOS)
```

`setup` asks for the server address and token, checks them with the server, and finds your WoW folder. If WoW is somewhere unusual, add `--wow "D:\Games\World of Warcraft\_retail_"`.

| Command | What it does |
|---|---|
| `setup` | Save server and token (`--server`, `--token`, `--wow`, `--no-check`) |
| `status` | Show settings (token masked), the files found, the last upload, and ask the server |
| `once` | Upload now; `--force` re-sends an unchanged file |
| `run` | Watch and upload (what start-at-login runs); checks every 30 s |
| `addon enable` / `disable` / `status` | Feed the in-game addon (off by default; see below) |
| `install` / `uninstall` | Start at login via the Windows Startup folder or a macOS LaunchAgent |
| `version` | Print the version |

Exit codes: `0` ok, `1` failed or will retry, `2` uploader too old (update it), `3` token rejected or revoked, `64` bad usage, `78` not set up.

The log is `uploader.log` next to the config (rotated at 1 MB).

## In-game addon

The companion addon (`addon/WowFlipLedger`) shows your positions, watchlist targets and alerts in game: item tooltips, a `/wfl` panel and login alerts. WoW addons cannot reach the internet, so the uploader hands the data over as a file.

1. Copy the `WowFlipLedger` folder into `World of Warcraft\_retail_\Interface\AddOns\`.
2. Run `wowflipledger-uploader addon enable`.
3. Keep `run` going (or `install`). About every 5 minutes, and after each upload, it fetches your data from the server and replaces `Interface/AddOns/WowFlipLedger/Data.lua`, but only when something changed (or once an hour, so the addon's "as of" time stays honest).
4. In game, `/reload` to load the new data. WoW reads addon files only at login and `/reload`.

Limits: it writes that one file and nothing else under the WoW folder, never creates the addon folder (if it is missing the uploader logs that and skips), refuses a symlinked folder or file, and does nothing at all until you run `addon enable`. `addon disable` stops it and leaves `Data.lua` in place. The server's text is escaped into the file, never pasted in as code. Notes you type on the site are not sent.

## Unsigned builds

These binaries are not code-signed yet, so Windows SmartScreen ("Windows protected your PC", choose *More info* then *Run anyway*) and macOS Gatekeeper (right-click, *Open*; or `xattr -d com.apple.quarantine <file>`) will warn on first run. Check the download against `SHA256SUMS`.

## Build

No local Go install is needed; everything runs in Docker from the repo root:

```bash
scripts/build-uploader.sh          # vet, test, cross-compile, write uploader/dist/ and SHA256SUMS
scripts/uploader.sh go test ./...  # any go command in the toolchain image
```

Targets: `windows/amd64`, `darwin/amd64`, `darwin/arm64`, and `linux/amd64` (for Wine/Proton setups; start-at-login isn't supported there, so run `run` under your own service manager and pass `--wow`). Builds are reproducible (`-trimpath`, no cgo).

Plain `http://` servers are only accepted for `localhost`/`127.0.0.1`; set `WFL_ALLOW_INSECURE_HTTP=1` to allow another host on a trusted network. `WFL_CONFIG_DIR` overrides the config location.
