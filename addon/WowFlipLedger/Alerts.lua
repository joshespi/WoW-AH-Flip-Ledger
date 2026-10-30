-- Login alerts, and the /wfl command.
local ADDON, ns = ...

local function describe(a)
    local name = ns.esc(a.name)

    if a.kind == "buy_target" then
        return "Buy target: " .. name .. " is at " .. ns.money(a.floor) .. " (target " .. ns.money(a.target) .. ")"
    elseif a.kind == "sell_target" then
        return "Sell target: " .. name .. " is at " .. ns.money(a.floor) .. " (target " .. ns.money(a.target) .. ")"
    elseif a.kind == "profit" then
        return "In profit: " .. name .. " floor " .. ns.money(a.floor) .. " clears break-even " .. ns.money(a.target)
    end
    return name .. ": " .. tostring(a.kind)
end
ns.describeAlert = describe

function ns.printAlerts()
    local feed = ns.feed()
    if not feed then
        ns.print((ns.freshness()))
        return 0
    end

    local alerts = feed.alerts or {}
    for _, a in ipairs(alerts) do
        ns.print(describe(a))
    end
    return #alerts
end

-- Once per feed: a /reload with the same data stays quiet, new data announces again.
function ns.announce()
    WowFlipLedgerDB = WowFlipLedgerDB or {}
    local feed = ns.feed()

    if not feed then
        if not WowFlipLedgerDB.hinted then
            WowFlipLedgerDB.hinted = true
            ns.print("No ledger data yet. Run the uploader with 'addon enable', then /reload. /wfl help lists commands.")
        end
        return
    end

    local age = ns.age()
    if age and age > ns.STALE_AFTER then
        ns.print("Ledger data is " .. ns.ageText(age) .. ". Is the uploader running?")
    end

    if WowFlipLedgerDB.announced ~= feed.generated_at then
        WowFlipLedgerDB.announced = feed.generated_at
        ns.printAlerts()
    end
end

local events = CreateFrame("Frame")
events:RegisterEvent("PLAYER_LOGIN")
events:SetScript("OnEvent", function()
    -- Let the chat frame settle first.
    C_Timer.After(5, ns.announce)
end)

SLASH_WOWFLIPLEDGER1 = "/wfl"
SlashCmdList["WOWFLIPLEDGER"] = function(msg)
    msg = strlower(strtrim(msg or ""))

    if msg == "" then
        ns.togglePanel()
    elseif msg == "alerts" then
        if ns.printAlerts() == 0 and ns.feed() then
            ns.print("No alerts right now.")
        end
    elseif msg == "status" then
        ns.print((ns.freshness()))
    else
        ns.print("/wfl opens the panel, /wfl alerts lists alerts, /wfl status shows how old the data is.")
        ns.print("Data refreshes on /reload; the uploader writes it in the background.")
    end
end
