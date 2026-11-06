-- Loads the addon under plain Lua 5.1 with stubbed WoW APIs and the Data.lua the uploader renders.
-- It checks logic and that nothing errors; it cannot show how the UI looks or behaves in game.
-- Run with addon/tests/run.sh.

local DATA = assert(arg[1], "usage: lua run.lua <rendered Data.lua> <addon dir>")
local ADDON_DIR = assert(arg[2])

local T = dofile((arg[0]:match("^(.*)[/\]") or ".") .. "/harness.lua")
local check, eq = T.check, T.eq

-- WoW API stubs -------------------------------------------------------------------------------

local NOW = 1700000000
function time() return NOW end
function GetCoinTextureString(n)
    return string.format("%dg%02ds%02dc", math.floor(n / 10000), math.floor(n / 100) % 100, n % 100)
end
strlower = string.lower
strtrim = function(s) return (s:gsub("^%s+", ""):gsub("%s+$", "")) end
tinsert = table.insert
UISpecialFrames = {}
SlashCmdList = {}
Enum = { TooltipDataType = { Item = 0 } }
C_Timer = { After = function(_, f) f() end }

local hook
TooltipDataProcessor = { AddTooltipPostCall = function(_, f) hook = f end }

local printed = {}
function print(...) printed[#printed + 1] = table.concat({ ... }, " ") end

local texts = {}

local function mock()
    local o = { _scripts = {}, _shown = false }
    return setmetatable(o, {
        __index = function(t, k)
            if k == "CreateFontString" or k == "CreateTexture" then
                return function() return mock() end
            elseif k == "SetText" then
                return function(self, s) self._text = s; texts[#texts + 1] = s end
            elseif k == "SetScript" then
                return function(self, name, f) self._scripts[name] = f end
            elseif k == "Show" then
                return function(self) self._shown = true end
            elseif k == "Hide" then
                return function(self) self._shown = false end
            elseif k == "IsShown" then
                return function(self) return self._shown end
            elseif k == "GetFrameLevel" then
                return function() return 1 end
            end
            return function(self) return self end
        end,
    })
end

function CreateFrame(_, name)
    local f = mock()
    if name then _G[name] = f end
    return f
end

-- Load the addon the way WoW does: every file gets the addon name and a shared table -----------

dofile(DATA)
local ns = {}
for _, file in ipairs({ "Core.lua", "Recorder.lua", "Inventory.lua", "Tooltip.lua", "Panel.lua", "Alerts.lua" }) do
    assert(loadfile(ADDON_DIR .. "/" .. file))("WowFlipLedger", ns)
end

check(type(WFL_FEED) == "table", "Data.lua defines WFL_FEED")
eq(WFL_FEED.schema, 1, "schema")
check(hook ~= nil, "tooltip hook registered")

local function reset(feed)
    WFL_FEED = feed
    ns._index = nil
end

-- Tooltip lines ------------------------------------------------------------------------------

local function lineMap(lines)
    local m = {}
    for _, l in ipairs(lines) do m[l[1]] = l end
    return m
end

local wf = lineMap(assert(ns.tooltipLines(238371)))
eq(wf["Held"][2], "20 (avg 38g00s00c)", "held line")
eq(wf["Unrealized"][3], "green", "profit is green")
eq(wf["Market"][2], "below typical", "below chip")
eq(wf["Buy target"][2], "45g00s00c", "buy target from the watchlist")
eq(wf["Buy target"][3], "white", "buy target not reached")

local sw = lineMap(assert(ns.tooltipLines(238365)))
eq(sw["Unrealized"][3], "red", "underwater is red")
eq(sw["Unrealized"][2], "-0g15s00c", "negative money")
eq(sw["Market"][2], "collecting data", "no derived numbers yet")

local np = lineMap(assert(ns.tooltipLines(242639)))
eq(np["Floor now"][2], "no price yet", "no floor")
check(np["Unrealized"] == nil, "no unrealized without a price")
check(np["Market"][2] == "collecting data", "unpriced item is collecting data")

local bl = lineMap(assert(ns.tooltipLines(262792)))
eq(bl["Buy target"][3], "green", "at buy target")
eq(bl["Warning"][2], "falling knife", "falling knife")
check(bl["Sell target"] == nil, "no sell target")
eq(bl["Floor now"][2], "3g10s00c", "watch-only floor")

check(ns.tooltipLines(1) == nil, "unknown item has no lines")

-- Tooltip hook -------------------------------------------------------------------------------

local function tooltip()
    local t = { lines = {} }
    function t:AddLine(s) self.lines[#self.lines + 1] = s end
    function t:AddDoubleLine(l, r) self.lines[#self.lines + 1] = l .. "=" .. r end
    return t
end

local tt = tooltip()
hook(tt, { id = 238371 })
check(#tt.lines > 3, "hook adds lines for a ledger item")
eq(tt.lines[2], "WoW Flip Ledger", "hook adds a heading")

tt = tooltip(); hook(tt, { id = 1 })
eq(#tt.lines, 0, "hook ignores other items")
tt = tooltip(); hook(tt, {})
eq(#tt.lines, 0, "hook ignores data without an id")
tt = tooltip(); hook(nil, { id = 238371 })
eq(#tt.lines, 0, "hook ignores a missing tooltip")

issecretvalue = function() return true end
tt = tooltip(); hook(tt, { id = 238371 })
eq(#tt.lines, 0, "hook ignores secret values")
issecretvalue = nil

-- Rows ---------------------------------------------------------------------------------------

local pos = ns.rows("positions")
eq(#pos, 3, "three positions")
eq(pos[1].cells[1], "Wyrmfish", "first position")
eq(pos[1].cells[6], "profit, below typical", "status text")
eq(pos[1].colors[5], "green", "unrealized color")
eq(pos[2].cells[1], 'Swarmer ||cffff0000"Quoted"||r', "pipes in names are escaped")
eq(pos[3].cells[4], "-", "missing floor is a dash")
eq(pos[3].cells[6], "no price", "unpriced status")

local watch = ns.rows("watch")
eq(#watch, 2, "two watchlist rows")
eq(watch[2].cells[6], "at buy target, falling knife", "watch state")
eq(watch[2].colors[6], "red", "knife wins the color")
eq(watch[2].cells[4], "-", "no sell target is a dash")

-- Freshness ----------------------------------------------------------------------------------

local text, color = ns.freshness()
eq(text, "Data as of just now", "fresh data")
eq(color, "gray", "fresh color")

NOW = 1700000000 + 3 * 3600
text = ns.freshness()
eq(text, "Data as of 3 h ago", "hours")

NOW = 1700000000 + 2 * 86400
text, color = ns.freshness()
check(text:find("old"), "old data warns")
eq(color, "amber", "old data color")

NOW = 1700000000
WFL_FEED.prices_stale = true
text = ns.freshness()
check(text:find("prices stale"), "stale prices are flagged")
WFL_FEED.prices_stale = false

-- Alerts -------------------------------------------------------------------------------------

WowFlipLedgerDB = nil
printed = {}
ns.announce()
eq(#printed, 2, "first login prints both alerts")
check(printed[1]:find("Buy target: Bloomline is at 3g10s00c %(target 3g50s00c%)"), "buy alert text")
check(printed[2]:find("In profit: Wyrmfish"), "profit alert text")

printed = {}
ns.announce()
eq(#printed, 0, "a reload with the same data stays quiet")

WFL_FEED.generated_at = WFL_FEED.generated_at + 600
printed = {}
ns.announce()
eq(#printed, 2, "new data announces again")

NOW = WFL_FEED.generated_at + 3 * 86400
printed = {}
WFL_FEED.generated_at = WFL_FEED.generated_at - 1
ns.announce()
check(printed[1] and printed[1]:find("Is the uploader running"), "old data warns at login")
NOW = 1700000000

reset(nil)
WowFlipLedgerDB = nil
printed = {}
ns.announce()
eq(#printed, 1, "no data prints one hint")
printed = {}
ns.announce()
eq(#printed, 0, "the hint is shown once")
check(ns.tooltipLines(238371) == nil, "no data, no tooltip lines")
eq(#ns.rows("positions"), 0, "no data, no rows")
check(select(1, ns.freshness()):find("No data yet"), "no data message")

-- Panel and slash command --------------------------------------------------------------------

dofile(DATA)
reset(WFL_FEED)
texts = {}
SlashCmdList["WOWFLIPLEDGER"]("")
check(WowFlipLedgerFrame ~= nil and WowFlipLedgerFrame:IsShown(), "/wfl opens the panel")
WowFlipLedgerFrame._scripts.OnShow()

local function has(s)
    for _, t in ipairs(texts) do if t == s then return true end end
    return false
end
check(has("Wyrmfish"), "panel shows a position")
check(has("Held"), "positions header")
check(select(1, ns.freshness()) ~= nil, "freshness text")

SlashCmdList["WOWFLIPLEDGER"]("")
check(not WowFlipLedgerFrame:IsShown(), "/wfl again closes it")

-- An empty panel says why instead of showing a blank window.
local function sawText(prefix)
    for _, t in ipairs(texts) do
        if t:sub(1, #prefix) == prefix then return true end
    end
    return false
end

local saved = WFL_FEED
reset(nil)
texts = {}
SlashCmdList["WOWFLIPLEDGER"]("")
WowFlipLedgerFrame._scripts.OnShow()
check(sawText("No ledger data yet"), "no data: the panel explains what to do")
check(sawText("No data yet"), "no data: the header line says so")

reset({ schema = 1, generated_at = NOW, positions = {}, watchlist = {}, alerts = {}, summary = { realized = 0, unrealized = 0 } })
texts = {}
WowFlipLedgerFrame._scripts.OnShow()
check(sawText("No open positions"), "no positions: the panel says so")

reset(saved)
SlashCmdList["WOWFLIPLEDGER"]("")
check(not WowFlipLedgerFrame:IsShown(), "closed again")

printed = {}
SlashCmdList["WOWFLIPLEDGER"]("alerts")
eq(#printed, 2, "/wfl alerts lists them")
printed = {}
SlashCmdList["WOWFLIPLEDGER"]("status")
eq(#printed, 1, "/wfl status")
printed = {}
SlashCmdList["WOWFLIPLEDGER"]("help")
eq(#printed, 2, "/wfl help")
SlashCmdList["WOWFLIPLEDGER"]("  ALERTS  ")
check(true, "commands are trimmed and case-insensitive")

T.done()
