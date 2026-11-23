# WoW Flip Ledger (CurseForge project page)

Paste the text between the rules into the project's description. Everything else in this file is for you.

---

**WoW Flip Ledger records your auction house flips so the site can tell you whether you actually made gold.**

No TradeSkillMaster needed. The addon quietly notes:

- what you pay for commodities,
- what your auctions sell for (after the 5% cut), and what expires or is cancelled,
- what you hold across your characters (bags, bank, warband bank, auctions, mail).

It saves this in a file in your WoW folder when you log out or `/reload`. Addons cannot send data anywhere, so you upload the file yourself on the site. Nothing leaves your computer until you do.


**Commands:** `/wfl` panel, `/wfl alerts`, `/wfl status`, `/wfl recorder` (what has been recorded).

**Privacy:** the file holds item ids, quantities, prices, times and your realm. It has no character names (characters are an anonymous hash), and the site never stores the raw file.

Site and help: https://wowledger.joshespi.com

Not affiliated with Blizzard Entertainment or TradeSkillMaster. World of Warcraft is a trademark of Blizzard Entertainment.

---

## Before the first release (you, once)

1. Create the project on CurseForge (game: World of Warcraft, category: Auction & Economy). Pick a license (the repo has none yet, and CurseForge requires one), add a logo and a screenshot of the `/wfl` panel.
2. Done: project id 1783753 is the default in `scripts/publish-curseforge.sh` and in `WowFlipLedger.toc` (`X-Curse-Project-ID`).
3. Create an API token at https://www.curseforge.com/account/api-tokens and export it as `CURSEFORGE_TOKEN` when you publish. Never commit it.

## Each release

1. Bump `## Version:` in `WowFlipLedger.toc` and add a matching `## x.y.z` section to `CHANGELOG.md` (`scripts/build-addon.sh` refuses to build without it).
2. Check the `## Interface:` numbers against the live clients (`/dump (select(4, GetBuildInfo()))`).
3. `addon/tests/run.sh`, then `scripts/build-addon.sh`. It writes `addon/dist/wowflipledger-addon-<version>.zip`.
4. Upload it: `scripts/publish-curseforge.sh`, the Publish addon workflow, or the web form. `/setup` links to the project (`LEDGER_ADDON_URL`), so no deploy is needed.

## Game versions

- Retail: the TOC lists `120007, 120100, 120105`.
- World of Warcraft: Forever: interface `16001` (in the TOC, confirmed in game 2026-11-05). Select Forever's game version on the CurseForge file if CurseForge lists one.
