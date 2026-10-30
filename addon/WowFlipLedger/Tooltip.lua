-- Adds ledger lines to item tooltips (bags, AH, links).
local ADDON, ns = ...

local function onTooltip(tooltip, data)
    if not tooltip or not data or not data.id then
        return
    end
    -- Restricted values (instances, combat) cannot be used as table keys.
    if issecretvalue and issecretvalue(data.id) then
        return
    end

    local lines = ns.tooltipLines(data.id)
    if not lines then
        return
    end

    tooltip:AddLine(" ")
    tooltip:AddLine("WoW Flip Ledger", unpack(ns.COLOR.blue))

    for _, l in ipairs(lines) do
        local c = ns.COLOR[l[3]] or ns.COLOR.white
        tooltip:AddDoubleLine(l[1], l[2], 0.8, 0.8, 0.8, c[1], c[2], c[3])
    end
end

if TooltipDataProcessor and TooltipDataProcessor.AddTooltipPostCall and Enum and Enum.TooltipDataType then
    TooltipDataProcessor.AddTooltipPostCall(Enum.TooltipDataType.Item, onTooltip)
end

ns.onTooltip = onTooltip
