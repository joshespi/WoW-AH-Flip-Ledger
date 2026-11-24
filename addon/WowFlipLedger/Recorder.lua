-- Records your own auction house activity into WowFlipLedgerDB (a SavedVariables file the uploader
-- sends to the site). Addons cannot make network requests; this only writes the local file.
--
-- One string per record, "kind|time|realm|item|qty|a|b", so WoW writes one record per line and the
-- server reads it with a line scanner:
--   buy       a = total copper paid
--   sale      a = gross copper, b = AH cut (the price you got is a - b)
--   expired / cancelled
--   deposit   qty = units, a = deposit copper forfeited on them (written with an expired/cancelled)
-- Posts are kept as open lots ("item|time|qty|deposit" in d.lots): a sale uses a lot up (the deposit
-- comes back), an expiry or cancel uses it up and books its share of the deposit as lost.
-- Sales mail names the item but gives no id, so a sale whose name maps to more than one id (or none)
-- waits in `unresolved` and is never guessed.
local ADDON, ns = ...

ns.MAX_EVENTS = 50000
-- The log is cut back to MAX_EVENTS only once it is this far over, not on every new record.
ns.TRIM_SLACK = 1000
ns.MAX_NAMES = 6000
ns.MAX_SEEN = 400
ns.MAX_UNRESOLVED = 500
ns.MAX_LOTS = 2000
-- Mail lives 30 days after a 48 h auction; a lot older than this can no longer be collected.
local LOT_DAYS = 40

local AUCTION_MAIL_DAYS = 30
local DAY = 86400
local floor = math.floor

local function clean(s)
    return (tostring(s):gsub("[|%c]", " "))
end

-- The client sometimes answers "" (truthy in Lua) before the realm is known, which used to stamp
-- records with no realm; the server drops those. Skip empty answers and fall back to the last good one.
local function realmName(fn)
    local r = fn and fn()
    if type(r) == "string" and r ~= "" then
        return clean(r)
    end
end

-- Some clients (Forever among them) answer the realm calls with nothing for long stretches, so the
-- realm is also asked of UnitFullName and remembered per character. "" means not known yet: the
-- caller holds the record (ns.record) rather than guess, since a wrong realm books P/L wrongly.
local function unitRealm()
    return (select(2, UnitFullName("player")))
end

-- The character's key, "" before the GUID is known (it would give every character the same key).
local function who()
    return (UnitGUID and UnitGUID("player") and ns.charKey and ns.charKey()) or ""
end

function ns.realm()
    local d = ns.db()
    local r = realmName(GetNormalizedRealmName) or realmName(GetRealmName)
        or realmName(UnitFullName and unitRealm)
    local key = who()

    if r then
        d.realm = r
        if key ~= "" then
            d.charRealms[key] = r
        end
        return r
    end

    -- Another character's realm (d.realm) is not this one's; only before login is it all there is.
    if key ~= "" then
        return d.charRealms[key] or ""
    end
    return d.realm or ""
end

local function realmOf(s)
    return s:match("^[^|]*|[^|]*|([^|]+)|")
end

local function parseLot(s)
    local id, t, qty, deposit, realm = s:match("^(%d+)|(%d+)|(%d+)|(%d+)|([^|]*)$")
    return tonumber(id), tonumber(t), tonumber(qty), tonumber(deposit), realm
end

-- One-time migration for files saved before records were held: with a single realm in the file
-- (or a remembered one), records without a realm take it. No neighbour guessing across realms.
function ns.repairRealms(d)
    if d.realmRepaired then
        return
    end
    d.realmRepaired = true

    local seen, n, last = nil, 0, nil
    for _, list in ipairs({ d.events, d.unresolved }) do
        for _, s in ipairs(list) do
            local r = realmOf(s)
            if r then
                if r ~= seen then
                    seen, n = r, n + 1
                end
                if list == d.events then
                    last = r
                end
            end
        end
    end
    local only = (n == 1 and seen) or (n == 0 and d.realm ~= "" and d.realm) or nil

    if only then
        for _, list in ipairs({ d.events, d.unresolved }) do
            for i, s in ipairs(list) do
                local kind, t, rest = s:match("^([^|]*)|([^|]*)||(.*)$")
                if kind then
                    list[i] = kind .. "|" .. t .. "|" .. only .. "|" .. rest
                end
            end
        end
    end
    d.realm = (d.realm ~= "" and d.realm) or last or only

    -- A lot saved without a realm belongs to the latest known one.
    if d.realm and d.realm ~= "" then
        for i, s in ipairs(d.lots or {}) do
            if select(5, parseLot(s)) == "" then
                d.lots[i] = s .. d.realm
            end
        end
    end
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

local whole = ns.whole

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
            ns.takeLots(id, tonumber(f[5]) or 0, f[3])
        else
            still[#still + 1] = row
        end
    end

    d.unresolved = still
end

-- Records waiting for a realm ----------------------------------------------------------------

ns.MAX_PENDING = 500

local function dispatch(o, realm)
    if o.op == "buy" then
        ns.addEvent("buy", o.t, realm, o.id, o.qty, o.a)
    elseif o.op == "sale" then
        ns.addEvent("sale", o.t, realm, o.id, o.qty, o.a, o.b)
        ns.takeLots(o.id, o.qty, realm)
    elseif o.op == "unresolved" then
        ns.addUnresolved("sale", o.t, realm, o.name, o.qty, o.a, o.b)
    elseif o.op == "expiry" then
        ns.addEvent(o.kind, o.t, realm, o.id, o.qty)
        local matched, lost = ns.takeLots(o.id, o.qty, realm)
        if lost > 0 then
            ns.addEvent("deposit", o.t, realm, o.id, matched, lost)
        end
        if matched < o.qty then
            local d = ns.db()
            d.depositMissed = d.depositMissed + 1
        end
    elseif o.op == "lot" then
        ns.addLot(o.id, o.t, o.qty, o.a, realm)
    end
end

-- Books o under the character's realm, or keeps it (saved, so it survives /reload) until one is known.
function ns.record(o)
    local realm = ns.realm()
    if realm ~= "" then
        dispatch(o, realm)
        return
    end

    local d = ns.db()
    o.key = who()
    d.pendingRealm[#d.pendingRealm + 1] = o
    if #d.pendingRealm > ns.MAX_PENDING then
        table.remove(d.pendingRealm, 1)
    end
end

-- Books held records once this character's realm is known. A character flushes only its own.
function ns.flushRealm()
    local d = ns.db()
    if #d.pendingRealm == 0 then
        return
    end
    local realm = ns.realm()
    if realm == "" then
        return
    end

    local me, keep, held = who(), {}, d.pendingRealm
    d.pendingRealm = keep
    for _, o in ipairs(held) do
        if o.key == me then
            dispatch(o, realm)
        else
            keep[#keep + 1] = o
        end
    end
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
    ns.flushRealm()
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
    ns.record({ op = "buy", t = time(), id = p.id, qty = p.qty, a = total })
end

function ns.onPurchaseFailed()
    purchase = nil
end

-- Posts and deposits -------------------------------------------------------------------------

-- Posts waiting for the game to confirm them: { id, qty, deposit, at }.
local pending = {}
local PENDING_SECONDS = 300

function ns.addLot(id, t, qty, deposit, realm)
    local d = ns.db()
    realm = realm or ns.realm()
    if realm == "" then
        ns.record({ op = "lot", id = id, t = t, qty = qty, a = deposit })
        return
    end
    d.lots[#d.lots + 1] = join(id, t, qty, deposit, realm)

    local cutoff = t - LOT_DAYS * DAY
    while #d.lots > ns.MAX_LOTS or (d.lots[1] and (select(2, parseLot(d.lots[1])) or 0) < cutoff) do
        table.remove(d.lots, 1)
    end
end

-- Takes up to qty units from the item's oldest lots on that realm (the db is account-wide; a lot
-- older than LOT_DAYS can no longer be collected, so it never matches). Returns the units found and the deposit that
-- belonged to them (a lot's last units carry the rounding remainder).
function ns.takeLots(id, qty, realm, now)
    local d = ns.db()
    local need, matched, deposit = qty, 0, 0
    realm = realm or ns.realm()
    local oldest = (now or time()) - LOT_DAYS * DAY
    local keep = {}

    local prefix = string.format("%d|", id)

    for _, s in ipairs(d.lots) do
        local lid, t, lqty, ldep, lrealm
        if s:sub(1, #prefix) == prefix then
            lid, t, lqty, ldep, lrealm = parseLot(s)
        end

        if lid == id and lrealm == realm and t >= oldest and need > 0 and lqty and lqty > 0 then
            local take = math.min(lqty, need)
            local share = take == lqty and ldep or floor(ldep * take / lqty)

            need = need - take
            matched = matched + take
            deposit = deposit + share
            if lqty > take then
                keep[#keep + 1] = join(lid, t, lqty - take, ldep - share, lrealm)
            end
        else
            keep[#keep + 1] = s
        end
    end

    if matched > 0 then
        d.lots = keep
    end
    return matched, deposit
end

-- Hooked after PostCommodity: the deposit is quoted now, kept only if the game confirms the post.
function ns.onPost(location, duration, quantity)
    local ah = C_AuctionHouse
    if not (ah and ah.CalculateCommodityDeposit and C_Item and C_Item.GetItemID) then
        return
    end

    local ok, id = pcall(C_Item.GetItemID, location)
    local ok2, deposit = pcall(ah.CalculateCommodityDeposit, id, duration, quantity)
    quantity = tonumber(quantity)
    deposit = tonumber(deposit)

    if not (ok and ok2 and tonumber(id) and quantity and quantity > 0 and deposit and deposit > 0) then
        return
    end

    local now = time()
    while pending[1] and now - pending[1].at > PENDING_SECONDS do
        table.remove(pending, 1)
    end
    pending[#pending + 1] = { id = tonumber(id), qty = quantity, deposit = deposit, at = now }
    while #pending > 20 do
        table.remove(pending, 1)
    end
end

function ns.onPostCreated()
    local p = table.remove(pending, 1)
    if p and time() - p.at <= PENDING_SECONDS then
        ns.addLot(p.id, time(), p.qty, whole(p.deposit))
    end
end

function ns.onPostFailed()
    table.remove(pending, 1)
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

-- A mail's identity: the character first, since two alts can collect identical mails (the db is account-wide).
local function mailSig(kind, ...)
    return table.concat({ kind, ns.charKey and ns.charKey() or "", ... }, "|")
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

    ns.flushRealm()
    local d = ns.db()
    local _, _, _, subject, money, _, daysLeft, hasItem = GetInboxHeaderInfo(index)
    local now = time()
    -- Auction mail lives 30 days, so what is left dates it. A sale is booked when it happened.
    local t = now
    if daysLeft then
        t = math.min(now, floor(now + daysLeft * DAY - AUCTION_MAIL_DAYS * DAY))
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
        if not firstSight(d, mailSig("sale", clean(name), count, gross), t) then
            return
        end

        local id = ns.resolve(name)
        if id then
            ns.record({ op = "sale", t = t, id = id, qty = count, a = gross, b = cut or 0 })
        else
            ns.record({ op = "unresolved", t = t, name = clean(name), qty = count, a = gross, b = cut or 0 })
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
    if not hasItem or not firstSight(d, mailSig(kind, clean(subject), money or 0), t) then
        return
    end

    for _, it in ipairs(ns.attachments(index)) do
        ns.record({ op = "expiry", kind = kind, t = t, id = it.id, qty = it.qty })
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
    local counts = { buy = 0, sale = 0, expired = 0, cancelled = 0, deposit = 0 }

    for _, row in ipairs(d.events) do
        local kind = row:match("^(%a+)|")
        if kind and counts[kind] then
            counts[kind] = counts[kind] + 1
        end
    end

    return string.format(
        "Recording: %d buys, %d sales, %d expired, %d cancelled; %d deposits lost recorded (%d expired/cancelled had no or only a partly matching post); %d sales waiting on an item name; %d records waiting on a realm; %d buys missed. Client %s (%s), interface %s, project %s, auction house API %s.",
        counts.buy, counts.sale, counts.expired, counts.cancelled, counts.deposit, d.depositMissed, #d.unresolved, #d.pendingRealm, d.missed,
        tostring(c.version), tostring(c.build), tostring(c.toc), tostring(c.project), c.ah and "present" or "missing"
    )
end

-- Wiring -------------------------------------------------------------------------------------

ns.on("PLAYER_LOGIN", ns.flushRealm)
ns.on("PLAYER_ENTERING_WORLD", ns.flushRealm)
ns.on("PLAYER_LOGIN", ns.resolveWaiting)
ns.on("COMMODITY_PRICE_UPDATED", function(_, _, total)
    ns.onPriceQuote(total)
end)
ns.on("COMMODITY_PURCHASE_SUCCEEDED", ns.onPurchaseSucceeded)
ns.on("COMMODITY_PURCHASE_FAILED", ns.onPurchaseFailed)
ns.on("AUCTION_HOUSE_AUCTION_CREATED", ns.onPostCreated)
ns.on("AUCTION_HOUSE_POST_ERROR", ns.onPostFailed)

if C_AuctionHouse then
    for name, fn in pairs({ StartCommoditiesPurchase = ns.onPurchaseStart, ConfirmCommoditiesPurchase = ns.onPurchaseConfirm, PostCommodity = ns.onPost }) do
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
