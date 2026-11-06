-- Records your own auction house activity into WowFlipLedgerDB (a SavedVariables file the uploader
-- sends to the site). Addons cannot make network requests; this only writes the local file.
--
-- One string per record, "kind|time|realm|item|qty|a|b", so WoW writes one record per line and the
-- server reads it with a line scanner:
--   buy       a = total copper paid
--   sale      a = gross copper, b = AH cut (the price you got is a - b)
--   expired / cancelled
-- Sales mail names the item but gives no id, so a sale whose name maps to more than one id (or none)
-- waits in `unresolved` and is never guessed.
local ADDON, ns = ...

ns.MAX_EVENTS = 50000
-- The log is cut back to MAX_EVENTS only once it is this far over, not on every new record.
ns.TRIM_SLACK = 1000
ns.MAX_NAMES = 6000
ns.MAX_SEEN = 400
ns.MAX_UNRESOLVED = 500

local AUCTION_MAIL_DAYS = 30
local floor = math.floor

local function clean(s)
    return (tostring(s):gsub("[|%c]", " "))
end

function ns.realm()
    return clean((GetNormalizedRealmName and GetNormalizedRealmName()) or (GetRealmName and GetRealmName()) or "")
end

-- Items by name -------------------------------------------------------------------------------

local itemName = (C_Item and C_Item.GetItemNameByID) or GetItemInfo

-- Ids already learned this session (scans see the same items over and over), for the current db.
local learned, learnedIn = {}, nil

function ns.learn(id, name)
    id = tonumber(id)
    if not id or id < 1 then
        return
    end

    local d = ns.db()
    if d ~= learnedIn then
        learned, learnedIn = {}, d
    end
    if learned[id] then
        return
    end

    name = name or (itemName and itemName(id))
    if type(name) ~= "string" or name == "" then
        return
    end

    learned[id] = true
    name = clean(name)
    local ids = d.names[name]

    if not ids then
        d.nameCount = (d.nameCount or 0) + 1
        if d.nameCount > ns.MAX_NAMES then
            return
        end
        d.names[name] = { id }
        return
    end

    for _, known in ipairs(ids) do
        if known == id then
            return
        end
    end
    ids[#ids + 1] = id
end

-- The id for a name, only when exactly one item is known by it. A name we have never seen is
-- asked of the client, which knows items it has cached (bags, recent links).
function ns.resolve(name)
    local ids = ns.db().names[clean(name or "")]

    if ids then
        return #ids == 1 and ids[1] or nil
    end

    local instant = C_Item and C_Item.GetItemInfoInstant
    if instant and type(name) == "string" and name ~= "" then
        local ok, id = pcall(instant, name)
        if ok and tonumber(id) then
            ns.learn(id, name)
            return tonumber(id)
        end
    end
end

-- Records ------------------------------------------------------------------------------------

local function whole(n)
    return floor((tonumber(n) or 0) + 0.5)
end

local function join(...)
    local parts = { ... }
    for i = 1, #parts do
        parts[i] = type(parts[i]) == "number" and string.format("%d", whole(parts[i])) or clean(parts[i])
    end
    return table.concat(parts, "|")
end

local function trim(d)
    local over = #d.events - ns.MAX_EVENTS
    if over <= ns.TRIM_SLACK then
        return
    end

    local kept = {}
    for i = over + 1, #d.events do
        kept[#kept + 1] = d.events[i]
    end
    d.events = kept
end

function ns.addEvent(kind, t, realm, id, qty, a, b)
    local d = ns.db()
    local row = { kind, t, realm, id, qty }
    if a ~= nil then
        row[#row + 1] = a
    end
    if b ~= nil then
        row[#row + 1] = b
    end

    d.events[#d.events + 1] = join(unpack(row))
    d.last = time()
    trim(d)
end

function ns.addUnresolved(kind, t, realm, name, qty, a, b)
    local d = ns.db()
    d.unresolved[#d.unresolved + 1] = join(kind, t, realm, name, qty, a, b)
    -- A name that never resolves must not grow the file without limit.
    while #d.unresolved > ns.MAX_UNRESOLVED do
        table.remove(d.unresolved, 1)
    end
end

-- Moves waiting sales to the log once their name maps to one item.
function ns.resolveWaiting()
    local d = ns.db()
    local still = {}

    for _, row in ipairs(d.unresolved) do
        local f = {}
        for part in (row .. "|"):gmatch("(.-)|") do
            f[#f + 1] = part
        end
        local id = ns.resolve(f[4])

        if id and f[1] == "sale" then
            ns.addEvent("sale", tonumber(f[2]), f[3], id, tonumber(f[5]), tonumber(f[6]), tonumber(f[7]) or 0)
        else
            still[#still + 1] = row
        end
    end

    d.unresolved = still
end

-- Buys ----------------------------------------------------------------------------------------

-- The price quote for the purchase in progress.
local purchase

local function quoteFromResults(id, qty)
    local ah = C_AuctionHouse
    if not (ah and ah.GetCommoditySearchResultsQuantity and ah.GetCommoditySearchResultInfo) then
        return nil
    end

    local ok, total = pcall(function()
        local need, sum = qty, 0
        for i = 1, ah.GetCommoditySearchResultsQuantity(id) do
            local r = ah.GetCommoditySearchResultInfo(id, i)
            if not r then
                break
            end
            local take = math.min(need, r.quantity)
            sum = sum + take * r.unitPrice
            need = need - take
            if need <= 0 then
                return sum
            end
        end
    end)

    return ok and total or nil
end

function ns.onPurchaseStart(id, qty)
    purchase = { id = tonumber(id), qty = tonumber(qty) }
end

function ns.onPriceQuote(total)
    if purchase then
        purchase.total = total
    end
end

function ns.onPurchaseConfirm(id, qty)
    if not purchase or purchase.id ~= tonumber(id) then
        purchase = { id = tonumber(id) }
    end
    purchase.qty = tonumber(qty)
    purchase.confirmed = true
end

function ns.onPurchaseSucceeded()
    local p = purchase
    purchase = nil

    if not (p and p.confirmed and p.id and p.qty and p.qty > 0) then
        return
    end

    local total = p.total or quoteFromResults(p.id, p.qty)
    if not total or total <= 0 then
        ns.db().missed = ns.db().missed + 1
        return
    end

    ns.learn(p.id)
    ns.addEvent("buy", time(), ns.realm(), p.id, p.qty, total)
end

function ns.onPurchaseFailed()
    purchase = nil
end

-- Mail: sales, expiries, cancels -----------------------------------------------------------------

-- "Auction expired: %s" as a pattern, from the game's own text so every locale works.
local function subjectPattern(fmt)
    if type(fmt) ~= "string" or not fmt:find("%%s") then
        return nil
    end

    local p = fmt:gsub("[%^%$%(%)%%%.%[%]%*%+%-%?]", "%%%0"):gsub("%%%%s", ".+")
    return "^" .. p .. "$"
end

local patterns = {}

local function subjectIs(subject, fmt)
    if type(fmt) == "string" and patterns[fmt] == nil then
        patterns[fmt] = subjectPattern(fmt) or false
    end
    local p = type(fmt) == "string" and patterns[fmt]
    return (p and type(subject) == "string" and subject:find(p) ~= nil) or false
end

-- True the first time a mail is seen (it may be touched by several hooks, or again next session).
local function firstSight(d, sig, t)
    local bucket = floor(t / 600)

    for b = bucket - 1, bucket + 1 do
        if d.seen[sig .. "#" .. b] then
            return false
        end
    end

    d.seen[sig .. "#" .. bucket] = true
    d.seenOrder = d.seenOrder or {}
    d.seenOrder[#d.seenOrder + 1] = sig .. "#" .. bucket

    while #d.seenOrder > ns.MAX_SEEN do
        d.seen[table.remove(d.seenOrder, 1)] = nil
    end

    return true
end

-- A mail's attachments as {id, qty}, learning their names on the way. Inventory sums them too.
function ns.attachments(index)
    local out = {}
    for j = 1, (ATTACHMENTS_MAX_RECEIVE or 16) do
        local name, id, _, count = GetInboxItem(index, j)
        if id then
            ns.learn(id, name)
            out[#out + 1] = { id = id, qty = count or 1 }
        end
    end
    return out
end

function ns.onMailTaken(index)
    index = tonumber(index)
    if not index or not GetInboxHeaderInfo then
        return
    end

    local d = ns.db()
    local _, _, _, subject, money, _, daysLeft, hasItem = GetInboxHeaderInfo(index)
    local now = time()
    -- Auction mail lives 30 days, so what is left dates it. A sale is booked when it happened.
    local t = now
    if daysLeft then
        t = math.min(now, floor(now + daysLeft * 86400 - AUCTION_MAIL_DAYS * 86400))
    end

    -- The invoice is only readable once the mail has been opened.
    if GetInboxText then
        pcall(GetInboxText, index)
    end

    local invoiceType, name, _, bid, buyout, _, cut, _, _, _, count = GetInboxInvoiceInfo(index)

    if invoiceType == "seller" then
        local gross = (bid and bid > 0) and bid or buyout
        count = tonumber(count) or 0

        if not gross or gross <= 0 or count < 1 then
            return
        end
        if not firstSight(d, "sale|" .. clean(name) .. "|" .. count .. "|" .. gross, t) then
            return
        end

        local id = ns.resolve(name)
        if id then
            ns.addEvent("sale", t, ns.realm(), id, count, gross, cut or 0)
        else
            ns.addUnresolved("sale", t, ns.realm(), name, count, gross, cut or 0)
        end
        return
    end

    local kind
    if subjectIs(subject, AUCTION_EXPIRED_MAIL_SUBJECT) then
        kind = "expired"
    elseif subjectIs(subject, AUCTION_REMOVED_MAIL_SUBJECT) then
        kind = "cancelled"
    end
    if not kind then
        return
    end

    -- TakeInboxItem fires once per attachment: dedupe before reading all the slots.
    if not hasItem or not firstSight(d, kind .. "|" .. clean(subject) .. "|" .. (money or 0), t) then
        return
    end

    for _, it in ipairs(ns.attachments(index)) do
        ns.addEvent(kind, t, ns.realm(), it.id, it.qty)
    end
end

-- Status -------------------------------------------------------------------------------------

function ns.clientInfo()
    local version, build, _, toc
    if GetBuildInfo then
        version, build, _, toc = GetBuildInfo()
    end
    return { version = version, build = build, toc = toc, project = WOW_PROJECT_ID, ah = C_AuctionHouse ~= nil }
end

function ns.recorderStatus()
    local d = ns.db()
    local c = ns.clientInfo()
    local counts = { buy = 0, sale = 0, expired = 0, cancelled = 0 }

    for _, row in ipairs(d.events) do
        local kind = row:match("^(%a+)|")
        if kind and counts[kind] then
            counts[kind] = counts[kind] + 1
        end
    end

    return string.format(
        "Recording: %d buys, %d sales, %d expired, %d cancelled; %d sales waiting on an item name; %d buys missed. Client %s (%s), interface %s, project %s, auction house API %s.",
        counts.buy, counts.sale, counts.expired, counts.cancelled, #d.unresolved, d.missed,
        tostring(c.version), tostring(c.build), tostring(c.toc), tostring(c.project), c.ah and "present" or "missing"
    )
end

-- Wiring -------------------------------------------------------------------------------------

ns.on("PLAYER_LOGIN", ns.resolveWaiting)
ns.on("COMMODITY_PRICE_UPDATED", function(_, _, total)
    ns.onPriceQuote(total)
end)
ns.on("COMMODITY_PURCHASE_SUCCEEDED", ns.onPurchaseSucceeded)
ns.on("COMMODITY_PURCHASE_FAILED", ns.onPurchaseFailed)

if C_AuctionHouse then
    for name, fn in pairs({ StartCommoditiesPurchase = ns.onPurchaseStart, ConfirmCommoditiesPurchase = ns.onPurchaseConfirm }) do
        if type(C_AuctionHouse[name]) == "function" then
            hooksecurefunc(C_AuctionHouse, name, fn)
        end
    end
end

-- Each of these collects a mail's contents, so a mail is booked when you take it.
for _, name in ipairs({ "TakeInboxMoney", "TakeInboxItem", "AutoLootMailItem" }) do
    if type(_G[name]) == "function" then
        hooksecurefunc(name, ns.onMailTaken)
    end
end
