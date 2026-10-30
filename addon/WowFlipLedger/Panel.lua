-- The /wfl window: positions and watchlist.
local ADDON, ns = ...

local ROW_H = 18
local COLS = {
    { x = 4, w = 200 },
    { x = 208, w = 50 },
    { x = 262, w = 100 },
    { x = 366, w = 100 },
    { x = 470, w = 100 },
    { x = 574, w = 130 },
}
local HEADERS = {
    positions = { "Item", "Held", "Avg cost", "Floor", "Unrealized", "Status" },
    watch = { "Item", "Floor", "Buy at", "Sell at", "Typical", "State" },
}

local frame, body, content, summary, empty, toggle, headerCells
local mode = "positions"
local pool = {}

local function setColor(fs, name)
    local c = ns.COLOR[name] or ns.COLOR.white
    fs:SetTextColor(c[1], c[2], c[3])
end

local function newFrame()
    local ok, f = pcall(CreateFrame, "Frame", "WowFlipLedgerFrame", UIParent, "BasicFrameTemplateWithInset")
    if ok and f then
        return f
    end

    -- If the stock template is ever gone, a plain frame with its own close button still works.
    f = CreateFrame("Frame", "WowFlipLedgerFrame", UIParent, "BackdropTemplate")
    f:SetBackdrop({ bgFile = "Interface\\DialogFrame\\UI-DialogBox-Background", edgeFile = "Interface\\DialogFrame\\UI-DialogBox-Border", edgeSize = 16, insets = { left = 4, right = 4, top = 4, bottom = 4 } })
    local close = CreateFrame("Button", nil, f, "UIPanelCloseButton")
    close:SetPoint("TOPRIGHT", -2, -2)
    return f
end

local function row(i)
    if pool[i] then
        return pool[i]
    end

    local r = CreateFrame("Frame", nil, content)
    r:SetSize(710, ROW_H)
    r:SetPoint("TOPLEFT", 0, -(i - 1) * ROW_H)
    r.cells = {}

    for c, col in ipairs(COLS) do
        local fs = r:CreateFontString(nil, "ARTWORK", "GameFontHighlightSmall")
        fs:SetPoint("LEFT", r, "LEFT", col.x, 0)
        fs:SetWidth(col.w)
        fs:SetJustifyH("LEFT")
        fs:SetWordWrap(false)
        r.cells[c] = fs
    end

    pool[i] = r
    return r
end

local function refresh()
    local text, color = ns.freshness()
    local feed = ns.feed()

    if feed and feed.summary then
        text = text .. "   Realized " .. ns.money(feed.summary.realized) .. "   Unrealized " .. ns.money(feed.summary.unrealized)
    end
    summary:SetText(text)
    setColor(summary, color)

    for c, label in ipairs(HEADERS[mode]) do
        headerCells[c]:SetText(label)
    end
    toggle:SetText(mode == "positions" and "Show watchlist" or "Show positions")

    local rows = ns.rows(mode)

    -- Say why the table is empty instead of leaving a blank window.
    if not feed then
        empty:SetText("No ledger data yet.\n\nRun the uploader with addon sync turned on\n(wowflipledger-uploader addon enable), then /reload.")
    elseif #rows == 0 then
        empty:SetText(mode == "positions" and "No open positions in your ledger." or "Your watchlist is empty.")
    else
        empty:SetText("")
    end

    for i, data in ipairs(rows) do
        local r = row(i)
        r:Show()
        for c = 1, #COLS do
            r.cells[c]:SetText(data.cells[c] or "")
            setColor(r.cells[c], data.colors[c])
        end
    end
    for i = #rows + 1, #pool do
        pool[i]:Hide()
    end

    content:SetHeight(math.max(1, #rows * ROW_H))
end

local function build()
    frame = newFrame()
    frame:SetSize(760, 470)
    frame:SetPoint("CENTER")
    frame:SetMovable(true)
    frame:EnableMouse(true)
    frame:RegisterForDrag("LeftButton")
    frame:SetScript("OnDragStart", frame.StartMoving)
    frame:SetScript("OnDragStop", frame.StopMovingOrSizing)
    frame:Hide()
    tinsert(UISpecialFrames, "WowFlipLedgerFrame")

    -- Everything we draw lives on `body`, a child frame above the template's. Text placed on the
    -- window frame itself is hidden behind the template's inset, which is a child frame too.
    body = CreateFrame("Frame", nil, frame)
    body:SetAllPoints(frame)
    body:SetFrameLevel(frame:GetFrameLevel() + 5)

    local title = body:CreateFontString(nil, "OVERLAY", "GameFontNormal")
    title:SetPoint("TOPLEFT", 12, -6)
    title:SetText("WoW Flip Ledger")

    summary = body:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
    summary:SetPoint("TOPLEFT", 12, -32)
    summary:SetWidth(600)
    summary:SetJustifyH("LEFT")

    empty = body:CreateFontString(nil, "OVERLAY", "GameFontNormal")
    empty:SetPoint("CENTER", body, "CENTER", 0, -20)
    empty:SetWidth(560)
    empty:SetJustifyH("CENTER")

    toggle = CreateFrame("Button", nil, body, "UIPanelButtonTemplate")
    toggle:SetSize(120, 22)
    toggle:SetPoint("TOPRIGHT", -36, -28)
    toggle:SetScript("OnClick", function()
        mode = mode == "positions" and "watch" or "positions"
        refresh()
    end)

    headerCells = {}
    for c, col in ipairs(COLS) do
        local fs = body:CreateFontString(nil, "OVERLAY", "GameFontNormalSmall")
        fs:SetPoint("TOPLEFT", 16 + col.x, -58)
        fs:SetWidth(col.w)
        fs:SetJustifyH("LEFT")
        headerCells[c] = fs
    end

    local scroll = CreateFrame("ScrollFrame", nil, body, "UIPanelScrollFrameTemplate")
    scroll:SetPoint("TOPLEFT", 12, -76)
    scroll:SetPoint("BOTTOMRIGHT", -32, 14)

    content = CreateFrame("Frame", nil, scroll)
    content:SetSize(710, 1)
    scroll:SetScrollChild(content)

    frame:SetScript("OnShow", refresh)
end

function ns.togglePanel()
    if not frame then
        build()
    end
    if frame:IsShown() then
        frame:Hide()
    else
        frame:Show()
    end
end
