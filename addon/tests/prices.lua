-- Drives Prices.lua under Lua 5.1 with stubbed WoW APIs, as a Forever client and as Retail.
-- Run with addon/tests/run.sh.
local ADDON_DIR = assert(arg[1], "usage: lua prices.lua <addon dir>")

local T = dofile((arg[0]:match("^(.*)[/\]") or ".") .. "/harness.lua")
local check, eq = T.check, T.eq

T.stubClock()
local NOW = T.now
local HOUR = 3600
local toc = 16001
function GetBuildInfo() return "1.60.1", "70790", "Nov 4 2026", toc end
function GetNormalizedRealmName() return "Realm" end
function GetTime() return 1 end

local frame = { registered = {} }
function CreateFrame()
    function frame:RegisterEvent(e) self.registered[e] = true end
    function frame:SetScript(name, f) self[name] = f end
    return frame
end
local function fire(event, ...) frame.OnEvent(frame, event, ...) end
function hooksecurefunc() end

Enum = { ItemCommodityStatus = { Unknown = 0, Item = 1, Commodity = 2 } }
local listings = {}
local partial = {}
local browse = {}
C_AuctionHouse = {
    GetNumCommoditySearchResults = function(id) return #(listings[id] or {}) end,
    GetCommoditySearchResultInfo = function(id, i) return (listings[id] or {})[i] end,
    HasFullCommoditySearchResults = function(id) return not partial[id] end,
    GetBrowseResults = function() return browse end,
    GetItemCommodityStatus = function(key)
        if key.uncached then return 0 end
        return key.commodity == false and 1 or 2
    end,
}

local ns = T.load(ADDON_DIR, { "Core.lua", "Recorder.lua", "Prices.lua" })
fire("PLAYER_LOGIN")
local d = ns.db()

check(ns.scansPrices(), "Forever scans prices")
for _, e in ipairs({ "COMMODITY_SEARCH_RESULTS_UPDATED", "COMMODITY_SEARCH_RESULTS_ADDED", "AUCTION_HOUSE_BROWSE_RESULTS_UPDATED", "AUCTION_HOUSE_BROWSE_RESULTS_ADDED" }) do
    check(frame.registered[e], e .. " is listened to")
end

-- A commodity search: lowest unit price, total quantity, listings.
listings[2770] = { { unitPrice = 150, quantity = 20 }, { unitPrice = 160, quantity = 300 }, { unitPrice = 175, quantity = 5 } }
fire("COMMODITY_SEARCH_RESULTS_UPDATED", 2770)
eq(#d.prices, 1, "one scan recorded")
eq(d.prices[1], string.format("%d|2770|150|325|3", NOW), "time|item|lowest|quantity|auctions")

-- A rescan in the same hour replaces it.
T.now = NOW + 600
listings[2770] = { { unitPrice = 140, quantity = 10 } }
fire("COMMODITY_SEARCH_RESULTS_UPDATED", 2770)
eq(#d.prices, 1, "same item, same hour: still one row")
eq(d.prices[1], string.format("%d|2770|140|10|1", NOW + 600), "the newest scan of the hour wins")

-- The next hour is a new row.
T.now = NOW + 2 * HOUR
fire("COMMODITY_SEARCH_RESULTS_UPDATED", 2770)
eq(#d.prices, 2, "a new hour adds a row")

-- Browse results: commodities only, and a search of the hour is kept over a browse.
browse = {
    { itemKey = { itemID = 2771 }, minPrice = 900, totalQuantity = 4000 },
    { itemKey = { itemID = 2770 }, minPrice = 999, totalQuantity = 1 },
    { itemKey = { itemID = 5000, commodity = false }, minPrice = 5, totalQuantity = 1 },
    { itemKey = { itemID = 2772 }, minPrice = 0, totalQuantity = 5 },
}
fire("AUCTION_HOUSE_BROWSE_RESULTS_UPDATED")
eq(#d.prices, 3, "one new item from the browse; gear, a zero price and a searched item are not added")
eq(d.prices[3], string.format("%d|2771|900|4000|0", NOW + 2 * HOUR), "a browse row has no listing count")
eq(d.prices[2], string.format("%d|2770|140|10|1", NOW + 2 * HOUR), "the exact search of the hour is kept")

-- Browse rows the client has no data for yet (status Unknown) fall back to the item key.
browse = {
    { itemKey = { itemID = 2774, uncached = true }, minPrice = 50, totalQuantity = 70 },
    { itemKey = { itemID = 5001, uncached = true, itemLevel = 200 }, minPrice = 5, totalQuantity = 1 },
}
fire("AUCTION_HOUSE_BROWSE_RESULTS_UPDATED")
eq(#d.prices, 4, "an uncached commodity is kept, uncached gear is not")
eq(d.prices[4], string.format("%d|2774|50|70|0", NOW + 2 * HOUR), "uncached commodity row")

-- A search the game has not fully loaded counts no listings, so a browse can replace it, and the
-- rest of the listings arriving (RESULTS_ADDED) makes it a full search.
partial[2775] = true
listings[2775] = { { unitPrice = 30, quantity = 100 } }
fire("COMMODITY_SEARCH_RESULTS_UPDATED", 2775)
eq(d.prices[5], string.format("%d|2775|30|100|0", NOW + 2 * HOUR), "a partial search counts no listings")
check(not ns.searchedThisHour(2775, NOW + 2 * HOUR), "a partial search is not a search of the hour")
partial[2775] = nil
listings[2775][2] = { unitPrice = 35, quantity = 900 }
fire("COMMODITY_SEARCH_RESULTS_ADDED", 2775)
eq(d.prices[5], string.format("%d|2775|30|1000|2", NOW + 2 * HOUR), "the full search replaces it")
eq(#d.prices, 5, "still one row for the hour")

-- Nothing is recorded for empty or broken results.
listings[2773] = {}
fire("COMMODITY_SEARCH_RESULTS_UPDATED", 2773)
fire("COMMODITY_SEARCH_RESULTS_UPDATED", nil)
eq(#d.prices, 5, "an empty search records nothing")

-- Cap and age.
ns.MAX_PRICES, ns.PRICE_SLACK = 10, 2
for i = 1, 20 do ns.addPrice(3000 + i, NOW + 2 * HOUR + i, 100, 5, 1) end
check(#d.prices <= 12, "the list is cut back past the slack (" .. #d.prices .. ")")
check(d.prices[#d.prices]:find("|3020|", 1, true) ~= nil, "newest kept")

ns.MAX_PRICES = 20000
d.prices = { string.format("%d|1|100|5|1", NOW - 20 * 86400), string.format("%d|2|100|5|1", NOW) }
T.now = NOW
fire("PLAYER_LOGIN")
eq(#d.prices, 1, "scans older than two weeks are dropped at login")

check(ns.priceStatus():find("1 scans", 1, true) ~= nil, "status counts the scans")

-- Retail records nothing.
toc = 120105
d.prices = {}
fire("COMMODITY_SEARCH_RESULTS_UPDATED", 2770)
fire("AUCTION_HOUSE_BROWSE_RESULTS_UPDATED")
eq(#d.prices, 0, "retail records no scans")
check(ns.priceStatus():find("not used on Retail", 1, true) ~= nil, "status says so")

-- A client without the auction house API loads and ignores the events.
toc = 16001
C_AuctionHouse = nil
fire("COMMODITY_SEARCH_RESULTS_UPDATED", 2770)
fire("AUCTION_HOUSE_BROWSE_RESULTS_UPDATED")
eq(#d.prices, 0, "no API, no error")

T.done()
