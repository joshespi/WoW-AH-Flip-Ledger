-- Loads Recorder.lua and Inventory.lua under Lua 5.1 with stubbed WoW APIs and drives them the way
-- the game would: purchase events, mailbox hooks, container scans. It checks the logic and the
-- records written; it cannot show what the real client sends. Run with addon/tests/run.sh.
local ADDON_DIR = assert(arg[1], "usage: lua recorder.lua <addon dir> [fixture out]")
local FIXTURE = arg[2]

local T = dofile((arg[0]:match("^(.*)[/\]") or ".") .. "/harness.lua")
local check, eq = T.check, T.eq

-- Stubs ---------------------------------------------------------------------------------------

local NOW = 1700000000
local DAY = 86400
function time() return NOW end
function GetTime() return 123.456 end
function GetNormalizedRealmName() return "Area52" end
function GetBuildInfo() return "12.1.5", "70783", "Nov 1 2026", 120105 end
WOW_PROJECT_ID = 1
UnitGUID = function() return "Player-3676-0A1B2C3D" end
C_Timer = { After = function(_, f) f() end }
C_Item = {
    GetItemNameByID = function(id) return ({ [238371] = "Wyrmfish", [238365] = "Swarmer", [238366] = "Swarmer" })[id] end,
    GetItemInfoInstant = function(name) return ({ ["Cached Thing"] = 4242 })[name] end,
}

local frame = { registered = {} }
function CreateFrame()
    function frame:RegisterEvent(e) self.registered[e] = true end
    function frame:SetScript(name, f) self[name] = f end
    return frame
end
local function fire(event, ...) frame.OnEvent(frame, event, ...) end

function hooksecurefunc(a, b, c)
    local tbl, name, fn = _G, a, b
    if type(a) == "table" then tbl, name, fn = a, b, c end
    local orig = tbl[name]
    tbl[name] = function(...)
        local r = orig(...)
        fn(...)
        return r
    end
end

-- Auction house
local results = {}
C_AuctionHouse = {
    StartCommoditiesPurchase = function() end,
    ConfirmCommoditiesPurchase = function() end,
    GetCommoditySearchResultsQuantity = function() return #results end,
    GetCommoditySearchResultInfo = function(_, i) return results[i] end,
    GetOwnedAuctions = function()
        return {
            { itemKey = { itemID = 238371 }, quantity = 50, status = 0 },
            { itemKey = { itemID = 238371 }, quantity = 25, status = 0 },
            { itemKey = { itemID = 238365 }, quantity = 99, status = 1 },
        }
    end,
}

-- Mailbox
local mail = {}
AUCTION_EXPIRED_MAIL_SUBJECT = "Auction expired: %s"
AUCTION_REMOVED_MAIL_SUBJECT = "Auction cancelled: %s"
ATTACHMENTS_MAX_RECEIVE = 16
function GetInboxNumItems() return #mail end
function GetInboxHeaderInfo(i)
    local m = mail[i]
    return nil, nil, "Auction House", m.subject, m.money or 0, 0, m.daysLeft or 29.5, m.items ~= nil
end
function GetInboxText() end
function GetInboxInvoiceInfo(i)
    local inv = mail[i].invoice
    if not inv then return nil end
    return inv.type, inv.name, nil, inv.bid, inv.buyout, inv.deposit, inv.cut, 0, 0, 0, inv.count, true
end
function GetInboxItem(i, j)
    local it = mail[i].items and mail[i].items[j]
    if it then return it.name, it.id, nil, it.qty end
end
function TakeInboxMoney() end
function TakeInboxItem() end
function AutoLootMailItem() end

-- Containers
local bank = false
local containers = {
    [0] = { { itemID = 238371, stackCount = 30 }, { itemID = 238371, stackCount = 20 }, { itemID = 5, stackCount = 1 } },
    [-1] = { { itemID = 238365, stackCount = 40 } },
    [13] = { { itemID = 238371, stackCount = 100 } },
    [-2] = { { itemID = 6, stackCount = 1 } },
}
Enum = { BagIndex = { Backpack = 0, Bank = -1, AccountBankTab_1 = 13, Keyring = -2 } }
C_Container = {
    GetContainerNumSlots = function(i)
        if (i == -1 or i == 13) and not bank then return 28 end -- a closed bank still reports slots...
        return containers[i] and #containers[i] or 0
    end,
    GetContainerItemInfo = function(i, s)
        if (i == -1 or i == 13) and not bank then return nil end -- ...but reads them as empty
        return containers[i] and containers[i][s]
    end,
}

-- Load ----------------------------------------------------------------------------------------

local FILES = { "Core.lua", "Recorder.lua", "Inventory.lua" }
local ns = {}
for _, file in ipairs(FILES) do
    assert(loadfile(ADDON_DIR .. "/" .. file))("WowFlipLedger", ns)
end

-- d.inv ("item:qty" strings) as id -> qty.
local function invMap(d)
    local m = {}
    for _, s in ipairs(d.inv) do
        local id, q = s:match("^(%d+):(%d+)$")
        m[tonumber(id)] = tonumber(q)
    end
    return m
end

check(frame.registered.COMMODITY_PURCHASE_SUCCEEDED, "listens for purchases")
check(frame.registered.MAIL_INBOX_UPDATE and frame.registered.PLAYER_LOGOUT, "listens for mail and logout")

WowFlipLedgerDB = nil
fire("PLAYER_LOGIN")
local d = WowFlipLedgerDB
eq(d.schema, 1, "schema")
eq(d.interface, 120105, "stamps the client's interface number")
eq(#d.events, 0, "starts empty")

-- Buys ---------------------------------------------------------------------------------------

C_AuctionHouse.StartCommoditiesPurchase(238371, 200)
fire("COMMODITY_PRICE_UPDATED", 40000, 8000000)
C_AuctionHouse.ConfirmCommoditiesPurchase(238371, 200)
fire("COMMODITY_PURCHASE_SUCCEEDED")
eq(d.events[1], "buy|1700000000|Area52|238371|200|8000000", "a confirmed purchase is booked with the quoted total")

C_AuctionHouse.StartCommoditiesPurchase(238371, 10)
fire("COMMODITY_PRICE_UPDATED", 50000, 500000)
C_AuctionHouse.ConfirmCommoditiesPurchase(238371, 10)
fire("COMMODITY_PURCHASE_FAILED")
fire("COMMODITY_PURCHASE_SUCCEEDED")
eq(#d.events, 1, "a failed purchase is not booked, and a stray success is ignored")

fire("COMMODITY_PURCHASE_SUCCEEDED")
eq(#d.events, 1, "success without a confirmation is ignored")

-- No price event: price it from the listings (two lots: 5 at 100, then 100 at 120).
results = { { quantity = 5, unitPrice = 100 }, { quantity = 100, unitPrice = 120 } }
C_AuctionHouse.StartCommoditiesPurchase(238371, 20)
C_AuctionHouse.ConfirmCommoditiesPurchase(238371, 20)
fire("COMMODITY_PURCHASE_SUCCEEDED")
eq(d.events[2], "buy|1700000000|Area52|238371|20|2300", "price falls back to walking the listings")

results = {}
C_AuctionHouse.StartCommoditiesPurchase(238371, 20)
C_AuctionHouse.ConfirmCommoditiesPurchase(238371, 20)
fire("COMMODITY_PURCHASE_SUCCEEDED")
eq(#d.events, 2, "no price at all books nothing")
eq(d.missed, 1, "and counts it as missed")

-- Sales --------------------------------------------------------------------------------------

mail = { { subject = "Auction successful: Wyrmfish", money = 1900000, daysLeft = 25, invoice = { type = "seller", name = "Wyrmfish", bid = 2000000, buyout = 0, deposit = 5000, cut = 100000, count = 50 } } }
TakeInboxMoney(1)
eq(d.events[3], "sale|" .. (NOW - 5 * DAY) .. "|Area52|238371|50|2000000|100000", "a sale is booked at the mail's date, gross and cut")

TakeInboxMoney(1)
AutoLootMailItem(1)
eq(#d.events, 3, "the same mail seen by another hook adds nothing")

mail = { { subject = "Auction successful: Mystery", money = 1, daysLeft = 20, invoice = { type = "seller", name = "Mystery", bid = 500, deposit = 0, cut = 25, count = 1 } } }
TakeInboxMoney(1)
eq(#d.events, 3, "a name nobody knows is not guessed")
eq(#d.unresolved, 1, "it waits instead")
eq(d.unresolved[1], "sale|" .. (NOW - 10 * DAY) .. "|Area52|Mystery|1|500|25", "waiting record")

ns.learn(777, "Mystery")
ns.resolveWaiting()
eq(#d.unresolved, 0, "a later-learned name releases it")
eq(d.events[4], "sale|" .. (NOW - 10 * DAY) .. "|Area52|777|1|500|25", "released sale is booked with its id")

-- A name we have never seen is asked of the client (it knows cached items).
mail = { { subject = "Auction successful: Cached Thing", money = 1, daysLeft = 10, invoice = { type = "seller", name = "Cached Thing", bid = 1000, deposit = 0, cut = 50, count = 4 } } }
TakeInboxMoney(1)
eq(d.events[5], "sale|" .. (NOW - 20 * DAY) .. "|Area52|4242|4|1000|50", "an unknown name the client can place is booked")
table.remove(d.events)

-- 238365 and 238366 share a name: ambiguous.
ns.learn(238365)
ns.learn(238366)
mail = { { subject = "Auction successful: Swarmer", money = 1, invoice = { type = "seller", name = "Swarmer", bid = 900, deposit = 0, cut = 45, count = 3 } } }
TakeInboxMoney(1)
eq(#d.events, 4, "two items with one name are not guessed")
eq(#d.unresolved, 1, "ambiguous sale waits")
d.unresolved = {}

mail = { { subject = "Sale Pending: Wyrmfish", invoice = { type = "seller_temp_invoice", name = "Wyrmfish", bid = 100, deposit = 0, cut = 5, count = 1 } } }
TakeInboxMoney(1)
eq(#d.events, 4, "a pending sale is not a sale")

mail = { { subject = "Auction won: Wyrmfish", invoice = { type = "buyer", name = "Wyrmfish", bid = 100, deposit = 0, cut = 0, count = 1 } } }
TakeInboxMoney(1)
eq(#d.events, 4, "won-auction mail is not booked (the purchase was)")

mail = { { subject = "Auction successful: Wyrmfish", money = 5, invoice = { type = "seller", name = "Wyrmfish", bid = 0, buyout = 0, cut = 0, count = 1 } } }
TakeInboxMoney(1)
eq(#d.events, 4, "an invoice with no price is ignored")

-- Expired and cancelled ---------------------------------------------------------------------

mail = { { subject = "Auction expired: Wyrmfish", daysLeft = 28, items = { { name = "Wyrmfish", id = 238371, qty = 120 } } } }
TakeInboxItem(1, 1)
eq(d.events[5], "expired|" .. (NOW - 2 * DAY) .. "|Area52|238371|120", "expired lot returns")
TakeInboxItem(1, 1)
eq(#d.events, 5, "taking it twice books it once")

mail = { { subject = "Auction cancelled: Wyrmfish", daysLeft = 29, items = { { name = "Wyrmfish", id = 238371, qty = 15 } } } }
TakeInboxItem(1, 1)
eq(d.events[6], "cancelled|" .. (NOW - 1 * DAY) .. "|Area52|238371|15", "cancelled lot returns")

mail = { { subject = "Hello from a friend", items = { { name = "Wyrmfish", id = 238371, qty = 1 } } } }
TakeInboxItem(1, 1)
eq(#d.events, 6, "ordinary mail is ignored")

-- Names with pipes or control characters cannot break the record format.
ns.addEvent("buy", NOW, "Bad|Realm\n", 1, 1, 1)
check(not d.events[7]:find("\n", 1, true) and select(2, d.events[7]:gsub("|", "")) == 5, "a pipe or newline in text cannot add fields")
table.remove(d.events)

-- Inventory ----------------------------------------------------------------------------------

fire("OWNED_AUCTIONS_UPDATED")
mail = { { subject = "Auction expired: Wyrmfish", items = { { name = "Wyrmfish", id = 238371, qty = 7 } } } }
fire("MAIL_INBOX_UPDATE")
fire("PLAYER_LOGOUT")

local inv = invMap(d)
-- bags 50 + auctions 75 + mail 7; closed bank and warband contribute nothing
eq(inv[238371], 132, "bags + owned auctions + mail, closed bank ignored")
check(inv[238365] == nil, "a sold lot is not stock and a closed bank is not read")
eq(inv[5], 1, "any bag item is counted")
check(inv[6] == nil, "the keyring is skipped")

bank = true
fire("BANKFRAME_OPENED")
fire("PLAYER_LOGOUT")
inv = invMap(d)
eq(inv[238371], 232, "an open bank adds its items and the warband bank once")
eq(inv[238365], 40, "bank item")

bank = false
fire("PLAYER_LOGOUT")
eq(d.inv[1] ~= nil and #d.inv > 0, true, "inventory is written")
eq(invMap(d)[238371], 232, "a later logout with the bank closed keeps the last bank count")

local keys = 0
for k in pairs(d.chars) do
    keys = keys + 1
    check(k:match("^%x%x%x%x%x%x%x%x$") ~= nil, "characters are keyed by a hash")
    check(not k:find("Player", 1, true), "the GUID is not stored")
end
eq(keys, 1, "one character")

-- Second character shares the warband bank but has its own bags.
UnitGUID = function() return "Player-3676-0FFFFFFF" end
containers[0] = { { itemID = 238371, stackCount = 1 } }
bank = false
fire("PLAYER_LOGIN")
fire("PLAYER_LOGOUT")
eq(invMap(d)[238371], 233, "a second character adds its own stock; the warband bank is not doubled")

-- Prune --------------------------------------------------------------------------------------

ns.MAX_EVENTS, ns.TRIM_SLACK = 5, 2
local kept = d.events
d.events = {}
for i = 1, 8 do ns.addEvent("expired", NOW + i, "Area52", 1, i) end
eq(#d.events, 5, "the log is cut back to the cap once it is past the slack")
eq(d.events[1], "expired|" .. (NOW + 4) .. "|Area52|1|4", "oldest dropped")
eq(d.events[#d.events], "expired|" .. (NOW + 8) .. "|Area52|1|8", "newest kept")
ns.addEvent("expired", NOW + 9, "Area52", 1, 9)
eq(#d.events, 6, "within the slack nothing is copied")
d.events = kept

-- Status -------------------------------------------------------------------------------------

local status = ns.recorderStatus()
check(status:find("interface 120105", 1, true) ~= nil, "status reports the interface number")
check(status:find("auction house API present", 1, true) ~= nil, "status reports the auction API")

-- Missing APIs must not break loading (another client flavour).
do
    local saved = { C_AuctionHouse = C_AuctionHouse, C_Container = C_Container, TakeInboxMoney = TakeInboxMoney }
    C_AuctionHouse, C_Container, TakeInboxMoney = nil, nil, nil
    local ns2 = {}
    for _, file in ipairs(FILES) do
        assert(loadfile(ADDON_DIR .. "/" .. file))("WowFlipLedger", ns2)
    end
    fire("PLAYER_LOGIN")
    fire("PLAYER_LOGOUT")
    check(ns2.recorderStatus():find("missing", 1, true) ~= nil, "no auction API is reported, not an error")
    C_AuctionHouse, C_Container, TakeInboxMoney = saved.C_AuctionHouse, saved.C_Container, saved.TakeInboxMoney
end

-- Fixture: the saved variables as WoW writes them, for the server's parser tests ----------------

if FIXTURE then
    local function dump(v, indent)
        indent = indent or ""
        if type(v) == "string" then
            return string.format("%q", v):gsub("\\\n", "\\n")
        elseif type(v) ~= "table" then
            return tostring(v)
        end

        local out, n = { "{" }, 0
        local keys = {}
        for k in pairs(v) do keys[#keys + 1] = k end
        table.sort(keys, function(a, b) return tostring(a) < tostring(b) end)

        local array = #v > 0
        for _, k in ipairs(keys) do
            n = n + 1
            if array then
                out[#out + 1] = indent .. "\t" .. dump(v[k], indent .. "\t") .. ", -- [" .. n .. "]"
            else
                local key = type(k) == "string" and string.format("[%q]", k) or "[" .. k .. "]"
                out[#out + 1] = indent .. "\t" .. key .. " = " .. dump(v[k], indent .. "\t") .. ","
            end
        end
        out[#out + 1] = indent .. "}"
        return table.concat(out, "\n")
    end

    local f = assert(io.open(FIXTURE, "w"))
    f:write("WowFlipLedgerDB = ", dump(d), "\n")
    f:close()
end

T.done()
