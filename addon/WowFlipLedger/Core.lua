-- Shared helpers. The data is WFL_FEED, written by the uploader into Data.lua and loaded at
-- login or /reload. Everything here only reads it.
local ADDON, ns = ...

ns.STALE_AFTER = 24 * 3600

ns.COLOR = {
    green = { 0.3, 1, 0.3 },
    red = { 1, 0.3, 0.3 },
    amber = { 1, 0.8, 0.2 },
    gray = { 0.6, 0.6, 0.6 },
    blue = { 0.4, 0.8, 1 },
    white = { 1, 1, 1 },
}

-- Item names come from the server; keep a stray "|" from being read as a chat escape code.
function ns.esc(s)
    return (tostring(s):gsub("|", "||"))
end

local coinText = (C_CurrencyInfo and C_CurrencyInfo.GetCoinTextureString) or GetCoinTextureString

-- Copper to a coin string; nil (no data) is a dash.
function ns.money(copper)
    if copper == nil then
        return "-"
    end

    local n = math.floor(math.abs(copper) + 0.5)
    local text
    if coinText then
        text = coinText(n)
    else
        text = string.format("%dg %ds %dc", math.floor(n / 10000), math.floor(n / 100) % 100, n % 100)
    end

    return copper < 0 and ("-" .. text) or text
end

function ns.feed()
    if type(WFL_FEED) == "table" then
        return WFL_FEED
    end
end

-- Positions and watchlist by item id. The feed cannot change within a session, so build once.
function ns.index()
    if ns._index then
        return ns._index
    end

    local idx = { pos = {}, watch = {} }
    local feed = ns.feed()

    if feed then
        for _, p in ipairs(feed.positions or {}) do
            idx.pos[p.item_id] = p
        end
        for _, w in ipairs(feed.watchlist or {}) do
            idx.watch[w.item_id] = w
        end
    end

    ns._index = idx
    return idx
end

function ns.age()
    local feed = ns.feed()
    if feed and feed.generated_at then
        return math.max(0, time() - feed.generated_at)
    end
end

function ns.ageText(seconds)
    if seconds < 90 then
        return "just now"
    elseif seconds < 3600 then
        return math.floor(seconds / 60) .. " min ago"
    elseif seconds < 86400 then
        return math.floor(seconds / 3600) .. " h ago"
    end
    return math.floor(seconds / 86400) .. " d ago"
end

-- One line saying how fresh the data is, and a color name for it.
function ns.freshness()
    local feed = ns.feed()
    if not feed then
        return "No data yet. Run the uploader with addon sync on, then /reload.", "red"
    end

    local age = ns.age()
    local text = "Data as of " .. (age and ns.ageText(age) or "an unknown time")
    local color = "gray"

    if age and age > ns.STALE_AFTER then
        text = text .. " (old: is the uploader running?)"
        color = "amber"
    end
    if feed.prices_stale then
        text = text .. " - prices stale"
        color = "amber"
    end

    return text, color
end

-- Lines for an item's tooltip: { left, right, colorName }. nil when the item is not in the ledger.
function ns.tooltipLines(id)
    local idx = ns.index()
    local p, w = idx.pos[id], idx.watch[id]

    if not p and not w then
        return nil
    end

    local lines = {}
    local function add(left, right, color)
        lines[#lines + 1] = { left, right, color or "white" }
    end

    if p then
        add("Held", p.held .. " (avg " .. ns.money(p.avg_cost) .. ")")
        add("Break-even", ns.money(p.break_even))
        if p.target_sell then
            add("Target sell", ns.money(p.target_sell))
        end
        if p.floor then
            add("Floor now", ns.money(p.floor))
        else
            add("Floor now", "no price yet", "gray")
        end
        if p.unrealized ~= nil then
            add("Unrealized", ns.money(p.unrealized), p.unrealized >= 0 and "green" or "red")
        end
        if p.market == "below" then
            add("Market", "below typical", "green")
        elseif p.market == "above" then
            add("Market", "above typical", "amber")
        elseif not p.stats_ready then
            add("Market", "collecting data", "gray")
        end
    end

    if w then
        if w.buy then
            add("Buy target", ns.money(w.buy), w.at_buy and "green" or "white")
        end
        if w.sell then
            add("Sell target", ns.money(w.sell), w.at_sell and "amber" or "white")
        end
        if w.knife then
            add("Warning", "falling knife", "red")
        end
        if not p and w.floor then
            add("Floor now", ns.money(w.floor))
        end
    end

    local age = ns.age()
    if age and age > ns.STALE_AFTER then
        add("Ledger data", ns.ageText(age), "amber")
    end

    return lines
end

-- Rows for the panel. mode is "positions" or "watch". Each row is { cells = {6 strings}, colors = { [column] = colorName } }.
function ns.rows(mode)
    local feed = ns.feed()
    local out = {}

    if not feed then
        return out
    end

    if mode == "watch" then
        for _, w in ipairs(feed.watchlist or {}) do
            local state, color = "", "gray"
            if w.at_buy then
                state, color = "at buy target", "green"
            elseif w.at_sell then
                state, color = "at sell target", "amber"
            end
            if w.knife then
                state = (state ~= "" and (state .. ", ") or "") .. "falling knife"
                color = "red"
            end

            out[#out + 1] = {
                cells = { ns.esc(w.name), ns.money(w.floor), ns.money(w.buy), ns.money(w.sell), ns.money(w.typical), state },
                colors = { [6] = color },
            }
        end
        return out
    end

    for _, p in ipairs(feed.positions or {}) do
        local state, color = "no price", "gray"
        if p.status == "profit" then
            state, color = "profit", "green"
        elseif p.status == "underwater" then
            state, color = "underwater", "red"
        end
        if p.market == "below" then
            state = state .. ", below typical"
        elseif p.market == "above" then
            state = state .. ", above typical"
        end

        out[#out + 1] = {
            cells = { ns.esc(p.name), tostring(p.held), ns.money(p.avg_cost), ns.money(p.floor), ns.money(p.unrealized), state },
            colors = { [5] = p.unrealized and (p.unrealized >= 0 and "green" or "red") or "gray", [6] = color },
        }
    end

    return out
end

function ns.print(msg)
    print("|cff66ccff[WFL]|r " .. msg)
end

-- Saved variables -----------------------------------------------------------------------------

ns.SCHEMA = 1

-- The table defaults were last applied to. WoW replaces the global when it loads the saved file,
-- so defaults are applied again whenever the table changes, and otherwise only once.
local ready

function ns.db()
    local d = WowFlipLedgerDB
    if d == ready and d ~= nil then
        return d
    end
    if type(d) ~= "table" then
        d = {}
        WowFlipLedgerDB = d
    end

    d.schema = ns.SCHEMA
    d.events = d.events or {}
    d.unresolved = d.unresolved or {}
    d.names = d.names or {}
    d.seen = d.seen or {}
    d.salt = d.salt or tostring(math.floor(time() * 7919 + (GetTime and GetTime() or 0) * 1000))
    d.missed = d.missed or 0
    -- Which client wrote the file (120105 retail, 16001 Forever, 11509 Classic Era): the server
    -- refuses a file uploaded as another game.
    local _, _, _, toc = (GetBuildInfo or function() end)()
    d.interface = tonumber(toc) or d.interface

    ready = d
    return d
end

-- Events --------------------------------------------------------------------------------------

-- One frame for the whole addon; a second handler for an event runs after the first.
local frame = CreateFrame("Frame")
local handlers = {}

function ns.on(event, fn)
    local prior = handlers[event]
    handlers[event] = prior and function(...)
        prior(...)
        fn(...)
    end or fn
    -- An event this client does not have must not break the others.
    pcall(frame.RegisterEvent, frame, event)
end

frame:SetScript("OnEvent", function(self, event, ...)
    local fn = handlers[event]
    if fn then
        fn(self, ...)
    end
end)
