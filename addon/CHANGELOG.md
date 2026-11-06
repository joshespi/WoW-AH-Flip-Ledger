# Changelog

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
