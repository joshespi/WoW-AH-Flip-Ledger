# WoW Flip Ledger (CurseForge project page)

Paste the text between the rules into the project's description. Everything else in this file is for you.

---

**WoW Flip Ledger records your auction house flips so the site can tell you whether you actually made gold.**

No TradeSkillMaster needed. The addon quietly notes:

- what you pay for commodities,
- what your auctions sell for (after the 5% cut), and what expires or is cancelled,
- what you hold across your characters (bags, bank, warband bank, auctions, mail).

It saves this in a file in your WoW folder when you log out or `/reload`. Addons cannot send data anywhere, so you send the file yourself: install the free uploader (it sends the file for you after each logout) or upload it by hand on the site. Nothing leaves your computer until you do.

In game you also get your cost, break-even and profit on item tooltips (including the auction house), a `/wfl` panel with your positions and watchlist, and a login alert when a watched item hits its target. That part needs the uploader, which refreshes the addon's data file.

**Commands:** `/wfl` panel, `/wfl alerts`, `/wfl status`, `/wfl recorder` (what has been recorded).

**Privacy:** the file holds item ids, quantities, prices, times and your realm. It has no character names (characters are an anonymous hash), and the site never stores the raw file.

Site, uploader and help: https://wowledger.joshespi.com

Not affiliated with Blizzard Entertainment or TradeSkillMaster. World of Warcraft is a trademark of Blizzard Entertainment.

---

## Before the first release (you, once)

1. Create the project on CurseForge (game: World of Warcraft, category: Auction & Economy). Pick a license (the repo has none yet, and CurseForge requires one), add a logo and a screenshot of the `/wfl` panel.
2. Put the numeric project id in `scripts/publish-curseforge.sh` (or the `CURSEFORGE_PROJECT_ID` variable) and add `## X-Curse-Project-ID: <id>` to `WowFlipLedger.toc`.
3. Create an API token at https://www.curseforge.com/account/api-tokens and export it as `CURSEFORGE_TOKEN` when you publish. Never commit it.

## Each release

1. Bump `## Version:` in `WowFlipLedger.toc` and add a matching `## x.y.z` section to `CHANGELOG.md` (`scripts/build-uploader.sh` refuses to build without it).
2. Check the `## Interface:` numbers against the live clients (`/dump (select(4, GetBuildInfo()))`).
3. `addon/tests/run.sh`, then `scripts/build-uploader.sh`. It writes the zip the site serves, `src/storage/app/uploader/wowflipledger-addon-<version>.zip`.
4. Upload that same zip to CurseForge so the site and CurseForge ship identical bytes: `scripts/publish-curseforge.sh` (or upload it in the web form). Then deploy the site so `/setup` offers the new version.

## Game versions

- Retail: the TOC lists `120007, 120100, 120105`.
- World of Warcraft: Forever: not verified. TradeSkillMaster's TOC on a Forever install lists `120105, 50504, 20506, 11509`, so its number is likely among those (or retail's). Log in and run `/dump (select(4, GetBuildInfo()))`, then add the number to the TOC and select Forever's game version on the CurseForge file.
