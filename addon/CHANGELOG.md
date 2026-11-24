# Changelog

## 1.0.2

- Web app changes only. No addon changes.

## 1.0.1

- Fixes purchases, sales and expiries still going missing on Forever. The realm is now also read from your character and remembered per character. A record made while the realm is still unknown is held (and saved) until it is known, then booked under it, instead of being dropped or guessed. Records already saved without a realm are filled in only when your file names a single realm. The web app now says how many records in an upload had no realm and were skipped.

## 1.0.0

- Shares the web app's release number.

## 0.4.3

- Republished so the CurseForge listing also covers the Forever client (1.60.1). No addon changes.

## 0.4.2

- Fixes a sale going missing when two of your characters collect an identical auction mail (same item, quantity and price) around the same time, as with bank alts. Each character's mail is now counted on its own.

## 0.4.1

- Fixes purchases, sales and expiries going missing from the site. The game sometimes reports an empty realm name, and records saved without a realm were dropped on upload. The addon now keeps the last known realm and, on load, fills in the realm of records already saved without one (when your records name a single realm).

## 0.4.0

- Records the auction house prices you see in World of Warcraft: Forever and Classic (lowest price, quantity, listings; at most one per item per hour), so the site can show your floors, history and "vs typical" there. Your scans are used for your account only. Retail is unchanged: its prices come from Blizzard.
- `/wfl recorder` reports how many price scans are recorded.

## 0.3.0

- Records the deposit you lose when an auction expires or is cancelled (matched to the post it came from, so the site can count it as a loss). Auctions posted before this version have no deposit on record.

## 0.2.2

- Internal clean-up, no behaviour change.

## 0.2.1

- Loads in World of Warcraft: Forever (interface 16001). Some 0.2.0 downloads lacked it, so Forever showed the addon as incompatible and recorded nothing. If yours did, install this version in `_forever_\Interface\AddOns`.
- The saved file now notes which game client wrote it, so the site refuses a Forever or Classic file uploaded as Retail (and the other way round) instead of mixing the ledgers.

## 0.2.0

- New: the addon now records your own auction house activity, so TradeSkillMaster is no longer needed.
  - Commodity purchases (price paid), sales (after the auction house cut), expired and cancelled auctions, and what you hold across your characters (bags, bank, warband bank, auctions, mail).
  - Saved to `WTF/Account/<account>/SavedVariables/WowFlipLedger.lua` when you log out or `/reload`. The WoW Flip Ledger uploader (or the site's upload page) sends that file to your ledger.
  - No character names are stored in the file: characters are a hash.
- New: `/wfl recorder` shows what has been recorded and what the client reports (interface number, auction house API).
- Sales mail names the item but not its id. A sale whose name matches more than one item (such as quality tiers of one reagent) is held back, not guessed.
- Tooltips, the `/wfl` panel and login alerts are unchanged.

## 0.1.0

- Item tooltip lines, the `/wfl` panel and login alerts, fed by the uploader's data file.
