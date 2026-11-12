-- Shared check helpers for the addon test scripts: local T = dofile("addon/tests/harness.lua").
local T = { checks = 0, failures = 0, print = print }

function T.check(cond, label)
    T.checks = T.checks + 1
    if not cond then
        T.failures = T.failures + 1
        T.print("FAIL: " .. label)
    end
end

function T.eq(got, want, label)
    T.check(got == want, label .. " (got " .. tostring(got) .. ", want " .. tostring(want) .. ")")
end

-- Loads the addon files in order with the addon name and one shared table, the way WoW does.
-- Returns the table.
function T.load(dir, files)
    local ns = {}
    for _, file in ipairs(files) do
        assert(loadfile(dir .. "/" .. file))("WowFlipLedger", ns)
    end
    return ns
end

-- Stubs the clock (T.now, settable) and C_Timer, which runs its callback at once.
T.now = 1700000000
function T.stubClock()
    function time() return T.now end
    C_Timer = { After = function(_, f) f() end }
end

-- Prints the summary and exits non-zero on any failure.
function T.done()
    T.print(string.format("%d checks, %d failed", T.checks, T.failures))
    os.exit(T.failures == 0 and 0 or 1)
end

return T
