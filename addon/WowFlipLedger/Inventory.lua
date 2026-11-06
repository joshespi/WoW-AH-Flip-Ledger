-- What you hold, summed over your characters, written to WowFlipLedgerDB.inv as "item:qty" strings
-- when you log out or /reload. Per character: bags (rescanned each time), bank (only while the bank
-- is open, since closed-bank slots read as empty), auctions and mail (as last seen). The warband
-- bank is shared, so it is kept once. Characters are keyed by a salted hash, never by name.
local ADDON, ns = ...

local function hash(s)
    local h = 5381
    for i = 1, #s do
        h = (h * 33 + s:byte(i)) % 4294967296
    end
    return string.format("%08x", h)
end

local function computeKey()
    local guid = (UnitGUID and UnitGUID("player")) or "unknown"
    return hash(guid .. ns.db().salt)
end

-- Set at login, when the GUID is known; until then it is worked out on each call.
local key

local function charKey()
    return key or computeKey()
end

local function add(map, id, qty)
    map[id] = (map[id] or 0) + (qty or 1)
end

-- Which store a container index belongs to, from the name Blizzard gives it (indices change
-- between patches; the names keep their meaning). nil skips it.
local function kindOf(name)
    local n = tostring(name):lower()

    if n:find("keyring") then
        return nil
    elseif n:find("account") then
        return "warband"
    elseif n:find("bank") then
        return "bank"
    end
    return "bags"
end

-- Container index -> store, worked out once. Keyed by index so an alias name cannot count a bag twice.
local stores = {}
if Enum and Enum.BagIndex then
    for name, index in pairs(Enum.BagIndex) do
        stores[index] = stores[index] or kindOf(name)
    end
end

local bankOpen = false

local function scan(kinds)
    local out = {}

    if not (Enum and Enum.BagIndex and C_Container) then
        return out
    end

    for index, kind in pairs(stores) do
        local slots = kinds[kind] and C_Container.GetContainerNumSlots(index)

        if slots and slots > 0 then
            out[kind] = out[kind] or {}
            for slot = 1, slots do
                local info = C_Container.GetContainerItemInfo(index, slot)
                if info and info.itemID then
                    add(out[kind], info.itemID, info.stackCount)
                    ns.learn(info.itemID)
                end
            end
        end
    end

    return out
end

local function mine()
    local d = ns.db()
    d.chars = d.chars or {}

    local key = charKey()
    d.chars[key] = d.chars[key] or {}

    return d.chars[key], d
end

local function scanBags()
    local c = mine()
    c.bags = scan({ bags = true }).bags or {}
    c.t = time()
end

local function scanBank()
    if not bankOpen then
        return
    end

    local c, d = mine()
    local found = scan({ bank = true, warband = true })

    if found.bank then
        c.bank = found.bank
    end
    if found.warband then
        d.warband = found.warband
    end
end

local function scanAuctions()
    local ah = C_AuctionHouse
    if not (ah and ah.GetOwnedAuctions) then
        return
    end

    local c = mine()
    local auc = {}

    for _, a in ipairs(ah.GetOwnedAuctions() or {}) do
        local id = a.itemKey and a.itemKey.itemID
        -- Sold lots are money waiting in the mail, not stock.
        if id and (a.status == nil or a.status == 0) then
            add(auc, id, a.quantity)
            ns.learn(id)
        end
    end
    c.auc = auc
end

local function scanMail()
    if not (GetInboxNumItems and GetInboxItem) then
        return
    end

    local c = mine()
    local mail = {}

    for i = 1, (GetInboxNumItems()) do
        -- The header says whether there is anything to walk 16 slots for.
        if not GetInboxHeaderInfo or select(8, GetInboxHeaderInfo(i)) then
            for _, a in ipairs(ns.attachments(i)) do
                add(mail, a.id, a.qty)
            end
        end
    end
    c.mail = mail
end

-- Runs fn once, a moment after the first of a burst of calls (taking mail or moving bank items
-- fires the update event many times).
local function coalesced(delay, fn)
    local pending = false
    return function()
        if pending then
            return
        end
        pending = true
        C_Timer.After(delay, function()
            pending = false
            fn()
        end)
    end
end

local queueBank = coalesced(1, scanBank)
local queueMail = coalesced(0.5, scanMail)

-- Sums every character's stores and the warband bank into d.inv.
function ns.flushInventory()
    local d = ns.db()
    local total = {}

    for _, c in pairs(d.chars or {}) do
        -- A store that was never scanned is nil.
        for _, name in ipairs({ "bags", "bank", "auc", "mail" }) do
            for id, qty in pairs(c[name] or {}) do
                add(total, id, qty)
            end
        end
    end
    for id, qty in pairs(d.warband or {}) do
        add(total, id, qty)
    end

    local ids = {}
    for id in pairs(total) do
        ids[#ids + 1] = id
    end
    table.sort(ids)

    d.inv = {}
    for _, id in ipairs(ids) do
        d.inv[#d.inv + 1] = string.format("%d:%d", id, total[id])
    end
    d.last = time()
end

ns.on("PLAYER_LOGIN", function()
    key = computeKey()
end)
ns.on("PLAYER_LOGIN", scanBags)

-- Bags are rescanned at logout; only the bank needs catching while it is open.
ns.on("BAG_UPDATE_DELAYED", function()
    if bankOpen then
        queueBank()
    end
end)

ns.on("BANKFRAME_OPENED", function()
    bankOpen = true
    queueBank()
end)

ns.on("BANKFRAME_CLOSED", function()
    bankOpen = false
end)

ns.on("OWNED_AUCTIONS_UPDATED", scanAuctions)
ns.on("MAIL_INBOX_UPDATE", queueMail)

ns.on("PLAYER_LOGOUT", function()
    scanBags()
    -- Names learned this session may release sales that were waiting.
    ns.resolveWaiting()
    ns.flushInventory()
end)
