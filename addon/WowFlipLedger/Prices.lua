-- Records the auction house prices you see, for games with no Blizzard price feed (Forever,
-- Classic), into WowFlipLedgerDB.prices. The site turns them into the floor and history Retail gets
-- from Blizzard. Retail records nothing here (its prices come from the API).
--
-- One string per scan, "time|item|lowest|quantity|auctions" (lowest is copper per unit), kept once
-- per item per hour (the newest scan of the hour). Two sources:
--   * a commodity search (you open an item's listings): the exact lowest price, the total quantity
--     and the number of listings. Until the game holds every listing (it loads more as you scroll)
--     the quantity is partial, so the row counts 0 listings, like a browse, and a browse or a full
--     search of the same hour replaces it.
--   * browse results (the list you see before opening an item): the lowest price and total quantity
--     the game shows, auctions = 0
local ADDON, ns = ...

ns.MAX_PRICES = 20000
ns.PRICE_SLACK = 500
local KEEP_DAYS = 14
local HOUR = 3600
local DAY = 86400
local MAX_RESULTS = 2000
local floor = math.floor

-- Retail interface numbers have six digits; every other client has no Blizzard feed.
function ns.scansPrices()
    local _, _, _, toc = (GetBuildInfo or function() end)()
    toc = tonumber(toc)
    return toc ~= nil and toc < 100000
end

local whole = ns.whole

-- Position of each "item:hour" in d.prices for the current table, so a rescan within the hour
-- replaces the earlier row.
local index, indexFor = {}, nil

local function rowTime(s)
    return tonumber(s:match("^(%d+)|"))
end

local function reindex(d)
    index, indexFor = {}, d.prices
    for i, s in ipairs(d.prices) do
        local t, id = s:match("^(%d+)|(%d+)|")
        if t then
            index[id .. ":" .. floor(t / HOUR)] = i
        end
    end
end

local function trim(d, now)
    local cutoff = now - KEEP_DAYS * DAY
    local over = #d.prices - ns.MAX_PRICES
    local oldFirst = d.prices[1] and rowTime(d.prices[1])

    if over <= ns.PRICE_SLACK and not (oldFirst and oldFirst < cutoff) then
        return
    end

    local kept = {}
    for i = math.max(1, over + 1), #d.prices do
        local t = rowTime(d.prices[i])
        if t and t >= cutoff then
            kept[#kept + 1] = d.prices[i]
        end
    end
    d.prices = kept
    index, indexFor = {}, nil
end

-- Records one scan. Prices are copper per unit; quantity the units listed.
function ns.addPrice(id, t, lowest, quantity, auctions)
    id, t, lowest, quantity = whole(id), whole(t), whole(lowest), whole(quantity)
    if id < 1 or lowest < 1 or quantity < 1 or not ns.scansPrices() then
        return
    end

    local d = ns.db()
    d.prices = d.prices or {}
    if indexFor ~= d.prices then
        reindex(d)
    end

    local row = string.format("%d|%d|%d|%d|%d", t, id, lowest, quantity, math.max(0, whole(auctions)))
    local key = id .. ":" .. floor(t / HOUR)
    local at = index[key]

    if at and d.prices[at] then
        d.prices[at] = row
    else
        d.prices[#d.prices + 1] = row
        index[key] = #d.prices
    end

    trim(d, t)
end

-- True when this hour already holds a search of the item (a row with listings counted).
function ns.searchedThisHour(id, t)
    local d = ns.db()
    if not d.prices then
        return false
    end
    if indexFor ~= d.prices then
        reindex(d)
    end

    local row = d.prices[index[whole(id) .. ":" .. floor(whole(t) / HOUR)] or 0]
    local auctions = row and tonumber(row:match("|(%d+)$"))
    return auctions ~= nil and auctions > 0
end

-- A commodity search finished: read the listings the game now holds for the item.
function ns.onCommodityResults(id)
    local ah = C_AuctionHouse
    id = tonumber(id)
    if not (id and ah and ah.GetNumCommoditySearchResults and ah.GetCommoditySearchResultInfo) then
        return
    end

    local ok, lowest, quantity, auctions = pcall(function()
        local n = math.min(ah.GetNumCommoditySearchResults(id) or 0, MAX_RESULTS)
        local low, qty = nil, 0

        for i = 1, n do
            local r = ah.GetCommoditySearchResultInfo(id, i)
            if not r then
                break
            end
            if r.unitPrice and (not low or r.unitPrice < low) then
                low = r.unitPrice
            end
            qty = qty + (r.quantity or 0)
        end

        return low, qty, n
    end)

    if not (ok and lowest) then
        return
    end

    local okFull, full = pcall(ah.HasFullCommoditySearchResults or function() return true end, id)
    if not (okFull and full) then
        auctions = 0
    end
    ns.addPrice(id, time(), lowest, quantity, auctions)
end

-- Whether a browse row is a commodity (priced per unit); gear and pets are not. The status is
-- Unknown until the client has the item's data, so that falls back to the item key.
local function isCommodity(ah, key)
    if ah.GetItemCommodityStatus and Enum and Enum.ItemCommodityStatus then
        local ok, status = pcall(ah.GetItemCommodityStatus, key)
        if ok and status ~= nil and status ~= Enum.ItemCommodityStatus.Unknown then
            return status == Enum.ItemCommodityStatus.Commodity
        end
    end

    return (key.itemLevel or 0) == 0 and (key.itemSuffix or 0) == 0 and (key.battlePetSpeciesID or 0) == 0
end

-- The browse list the game shows: lowest price and total quantity per item.
function ns.scanBrowse()
    local ah = C_AuctionHouse
    if not (ah and ah.GetBrowseResults) then
        return
    end

    local ok, results = pcall(ah.GetBrowseResults)
    if not (ok and type(results) == "table") then
        return
    end

    local now = time()
    for i = 1, math.min(#results, MAX_RESULTS * 5) do
        local r = results[i]
        local key = type(r) == "table" and r.itemKey
        if key and key.itemID and r.minPrice and r.totalQuantity and isCommodity(ah, key) then
            -- A search of the item (exact, with listings) is kept over a browse of the same hour.
            if not ns.searchedThisHour(key.itemID, now) then
                ns.addPrice(key.itemID, now, r.minPrice, r.totalQuantity, 0)
            end
        end
    end
end

-- Browse results arrive in many small events; read them once things have settled.
local waiting
local function onBrowse()
    if waiting or not ns.scansPrices() then
        return
    end

    waiting = true
    C_Timer.After(1, function()
        waiting = false
        ns.scanBrowse()
    end)
end

function ns.priceStatus()
    local d = ns.db()
    return string.format("Prices: %d scans recorded%s.", #(d.prices or {}), ns.scansPrices() and "" or " (not used on Retail)")
end

-- Wiring -------------------------------------------------------------------------------------

local function onCommodity(_, id)
    if ns.scansPrices() then
        ns.onCommodityResults(id)
    end
end

ns.on("COMMODITY_SEARCH_RESULTS_UPDATED", onCommodity)
ns.on("COMMODITY_SEARCH_RESULTS_ADDED", onCommodity)
ns.on("AUCTION_HOUSE_BROWSE_RESULTS_UPDATED", onBrowse)
ns.on("AUCTION_HOUSE_BROWSE_RESULTS_ADDED", onBrowse)
ns.on("PLAYER_LOGIN", function()
    local d = ns.db()
    if d.prices then
        trim(d, time())
    end
end)
