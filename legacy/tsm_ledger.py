#!/usr/bin/env python3
r"""
TSM ledger + market check. Read-only, stdlib only.

Reads:
  WTF/Account/<ACCOUNT>/SavedVariables/TradeSkillMaster.lua   sales log, inventory, item names
  Interface/AddOns/TradeSkillMaster_AppHelper/AppData.lua     region prices from the TSM app
  trades.csv                                                  your buys; TSM's buy log double-counts some commodity lots

Commands:
  ledger   P/L per item: trades.csv buys vs TSM auction sales, weighted avg cost, after AH cut
  market   watchlist vs region prices, with holdings and break-even
  scan     liquid commodities trading under their region sale avg

SavedVariables only update on /reload or logout.

  python tsm_ledger.py ledger
  python tsm_ledger.py market               uses watchlist.txt if present, else built-in list
  python tsm_ledger.py scan --max-ratio 0.75

Docker: see compose.yaml
"""
import argparse
import collections
import csv
import datetime as dt
import os
import platform
import re
import sys

AH_CUT = 0.05
DEFAULT_WOW = {
    'Windows': r'C:\Program Files (x86)\World of Warcraft\_retail_',
    'Darwin': '/Applications/World of Warcraft/_retail_',
}
DEFAULT_WATCH = [
    "Sin'dorei Swarmer", 'Practically Pork', 'Sunwell Fish', 'Void-Tempered Leather',
    'Void-Tempered Scales', 'Lynxfish', 'Shredded Glimmerline', 'Refulgent Copper Ore',
    'Brilliant Silver Ore', 'Umbral Tin Ore', 'Shredded Bloomline', 'Blood Hunter',
    'Eversong Trout', 'Arcane Wyrmfish',
]
PRICE_SETS = (
    'AUCTIONDB_COMMODITY_DATA', 'AUCTIONDB_COMMODITY_SCAN_STAT', 'AUCTIONDB_COMMODITY_HISTORICAL',
    'AUCTIONDB_REGION_STAT', 'AUCTIONDB_REGION_HISTORICAL', 'AUCTIONDB_REGION_SALE',
)


def base_id(item_string):
    return ':'.join(item_string.split(':')[:2])


def lua_unescape(s):
    def rep(m):
        e = m.group(1)
        return chr(int(e)) if e.isdigit() else {'n': '\n', 't': '\t', 'r': '\r'}.get(e, e)
    return re.sub(r'\\(\d{1,3}|.)', rep, s)


def fmt(copper):
    c = int(round(copper))
    sign, c = ('-' if c < 0 else ''), abs(c)
    g, s, cc = c // 10000, c // 100 % 100, c % 100
    return f'{sign}{g:,}g {s:02d}s {cc:02d}c' if g else f'{sign}{s}s {cc:02d}c'


def parse_gold(text):
    """'5031.74' (gold) or '5031g 74s 2c' -> copper"""
    s = text.strip().replace(',', '')
    m = re.fullmatch(r'(?:(\d+)g)?\s*(?:(\d+)s)?\s*(?:(\d+)c)?', s)
    if s and m:
        g, si, c = (int(x or 0) for x in m.groups())
        return g * 10000 + si * 100 + c
    return round(float(s) * 10000)


def local(ts):
    return dt.datetime.fromtimestamp(ts).strftime('%Y-%m-%d %H:%M')


def table(headers, rows):
    widths = [max(len(str(x)) for x in col) for col in zip(headers, *rows)]
    align = ['<'] + ['>'] * (len(headers) - 1)

    def line(r):
        return '  '.join(f'{str(x):{a}{w}}' for x, a, w in zip(r, align, widths))

    print(line(headers))
    print('  '.join('-' * w for w in widths))
    for r in rows:
        print(line(r))


class TSM:
    def __init__(self, path):
        with open(path, encoding='utf-8', errors='replace') as f:
            t = f.read().replace('\r\n', '\n')
        self.mtime = os.path.getmtime(path)
        self.names = self._names(t)
        self.csv = self._csv(t)
        self.inventory = self._inventory(t)

    @staticmethod
    def _names(t):
        # TSMItemInfoDB: parallel \x02-joined names / itemStrings, split across chunks
        sub = t[t.find('TSMItemInfoDB = {'):]

        def tbl(key):
            m = re.search(r'\n\["%s"\] = \{\n(.*?)\n\},' % key, sub, re.S)
            if not m:
                return []
            chunks = [lua_unescape(c) for c in re.findall(r'"((?:[^"\\]|\\.)*)"', m.group(1))]
            return '\x02'.join(chunks).split('\x02')

        names = {base_id(k): v for k, v in zip(tbl('itemStrings'), tbl('names'))}
        # sale hint keys: "Name\x01i:123\x01qty\x01price"
        for m in re.finditer(r'\["([^"\x01]+)\x01(i:\d+)', t):
            names.setdefault(m.group(2), m.group(1))
        return names

    @staticmethod
    def _csv(t):
        out = collections.defaultdict(list)
        for m in re.finditer(r'\["r@[^@"]*@internalData@(csv\w+)"\] = "((?:[^"\\]|\\.)*)"', t):
            lines = lua_unescape(m.group(2)).split('\n')
            hdr = lines[0].split(',')
            out[m.group(1)].extend(dict(zip(hdr, ln.split(','))) for ln in lines[1:] if ln)
        return out

    @staticmethod
    def _inventory(t):
        inv = collections.Counter()
        pat = r'\["[sg]@[^"]*@internalData@(?:bag|bank|reagentBank|auction|mail|warbank)Quantity"\] = \{(.*?)\n\}'
        for m in re.finditer(pat, t, re.S):
            for k, q in re.findall(r'\["(i:\d+)[^"]*"\] = (\d+)', m.group(1)):
                inv[k] += int(q)
        return inv


class AppData:
    def __init__(self, path, region):
        with open(path, encoding='utf-8', errors='replace') as f:
            s = f.read()
        self.sets, self.times = {}, {}
        pat = r'LoadData\("(\w+)","([^"]+)",\[\[return \{downloadTime=(\d+),fields=\{([^}]*)\},data=\{(.*?)\}\}\]\]'
        for kind, realm, ts, fields, data in re.findall(pat, s, re.S):
            if realm != region or kind not in PRICE_SETS:
                continue
            fields = [x.strip('"') for x in fields.split(',')][1:]
            rows = {}
            for r in re.findall(r'\{([^{}]*)\}', data):
                p = r.split(',')
                key = p[0].strip('"') if p[0].startswith('"') else 'i:%d' % int(p[0])
                rows[key] = {f: self._b32(v) for f, v in zip(fields, p[1:])}
            self.sets[kind] = rows
            self.times[kind] = int(ts)

    @staticmethod
    def _b32(v):
        try:
            return int(v, 32)
        except ValueError:
            return None

    def commodities(self):
        return self.sets.get('AUCTIONDB_COMMODITY_DATA', {}).keys()

    def item(self, key):
        d = {}
        for kind in PRICE_SETS:
            d.update(self.sets.get(kind, {}).get(key, {}))
        if d.get('regionSoldPerDay') is not None:
            d['regionSoldPerDay'] /= 1000  # stored x1000
        return d


def load_trades(path, names):
    by_name = collections.defaultdict(set)
    for k, n in names.items():
        by_name[n.lower()].add(k)
    out = []
    with open(path, newline='', encoding='utf-8') as f:
        for i, r in enumerate(csv.DictReader(f), 2):
            item = r['item'].strip()
            if not item.startswith('i:'):
                ids = by_name.get(item.lower(), set())
                if len(ids) != 1:
                    sys.exit(f'{path}:{i}: "{item}" matches {len(ids)} items, use i:ID')
                item = next(iter(ids))
            ts = dt.datetime.fromisoformat(r['time'].strip())
            if ts.tzinfo is None:
                ts = ts.astimezone()
            out.append({'ts': int(ts.timestamp()), 'item': base_id(item),
                        'qty': int(r['qty']), 'cost': parse_gold(r['cost'])})
    return out


def build_ledger(trades, sales):
    """Weighted avg cost. Sales count from an item's first tracked buy; extra beyond held qty = own stock."""
    ev = collections.defaultdict(list)
    for t in trades:
        ev[t['item']].append((t['ts'], 0, t['qty'], t['cost']))
    first = {k: min(e[0] for e in v) for k, v in ev.items()}
    for r in sales:
        k = base_id(r['itemString'])
        if r.get('source') == 'Auction' and k in first and int(r['time']) >= first[k]:
            q = int(r['quantity'])
            ev[k].append((int(r['time']), 1, q, q * int(r['price'])))  # TSM price is per unit, after cut
    out = {}
    for k, events in ev.items():
        a = dict.fromkeys(('bq', 'bc', 'sq', 'sr', 'pnl', 'oq', 'orev', 'inv', 'cost'), 0)
        for _, side, q, c in sorted(events):  # buys before sells on same timestamp
            if side == 0:
                a['inv'] += q
                a['cost'] += c
                a['bq'] += q
                a['bc'] += c
                continue
            m = min(q, a['inv'])
            cogs = a['cost'] * m // a['inv'] if a['inv'] else 0
            rev = c * m // q
            a['pnl'] += rev - cogs
            a['sq'] += m
            a['sr'] += rev
            a['oq'] += q - m
            a['orev'] += c - rev
            a['inv'] -= m
            a['cost'] -= cogs
        out[k] = a
    return out


def cmd_ledger(tsm, ledger):
    rows, tot = [], collections.Counter()
    for k, a in sorted(ledger.items(), key=lambda x: -x[1]['bc']):
        avg = a['bc'] / a['bq'] if a['bq'] else 0
        held_avg = a['cost'] / a['inv'] if a['inv'] else 0
        rows.append([
            tsm.names.get(k, k), f"{a['bq']:,}", fmt(a['bc']), fmt(avg), f"{a['sq']:,}" if a['sq'] else '', fmt(a['sr']) if a['sq'] else '',
            fmt(a['pnl']) if a['sq'] else '', f"{a['inv']:,}", fmt(held_avg) if a['inv'] else '',
            fmt(held_avg / (1 - AH_CUT)) if a['inv'] else '', f"{tsm.inventory.get(k, 0):,}",
            f"{a['oq']:,} / {fmt(a['orev'])}" if a['oq'] else '',
        ])
        tot.update({x: a[x] for x in ('bc', 'sr', 'pnl', 'cost', 'orev')})
    table(['Item', 'Bought', 'Cost', 'Avg buy', 'Sold', 'Net sales', 'P/L', 'Held', 'Held avg',
           'Break-even', 'TSM count', 'Own stock sold'], rows)
    print(f"\nRealized P/L {fmt(tot['pnl'])}   still in stock {fmt(tot['cost'])}   "
          f"own-stock sales {fmt(tot['orev'])}   (all after {AH_CUT:.0%} cut)")


def cmd_market(tsm, app, ledger, watch):
    rows = []
    for w in watch:
        if w.startswith('i:'):
            keys = [base_id(w)]
        else:
            keys = sorted((k for k in app.commodities() if tsm.names.get(k, '').lower() == w.lower()),
                          key=lambda k: int(k[2:]))
        if not keys:
            rows.append([w, 'not found'] + [''] * 9)
            continue
        for k in keys:
            p = app.item(k)
            floor, sale = p.get('minBuyout'), p.get('regionSale')
            ratio = floor / sale if floor and sale else None
            led = ledger.get(k)
            held = led['inv'] if led else tsm.inventory.get(k, 0)
            avg = led['cost'] / led['inv'] if led and led['inv'] else None
            note = []
            if ratio is not None and ratio <= 0.75:
                note.append('below avg')
            if ratio is not None and ratio >= 1.15:
                note.append('above avg')
            if avg and floor:
                note.append('in profit' if floor * (1 - AH_CUT) > avg else 'under water')
            label = tsm.names.get(k, k) + (f' ({k[2:]})' if len(keys) > 1 else '')
            rows.append([
                label, fmt(floor or 0), fmt(p.get('marketValueRecent') or 0), fmt(sale or 0),
                fmt(p.get('historical') or 0), f'{ratio:.2f}' if ratio else '',
                f"{p.get('regionSoldPerDay') or 0:,.0f}", f'{held:,}' if held else '',
                fmt(avg) if avg else '', fmt(avg / (1 - AH_CUT)) if avg else '', ', '.join(note),
            ])
    table(['Item', 'Floor', 'Recent', 'Sale avg', '60d hist', 'Floor/avg', 'Sold/day', 'Held',
           'Your avg', 'Break-even', 'Note'], rows)


def cmd_scan(tsm, app, min_id, min_spd, max_ratio, top):
    found = []
    for k in app.commodities():
        p = app.item(k)
        floor, sale, spd = p.get('minBuyout'), p.get('regionSale'), p.get('regionSoldPerDay')
        if not (floor and sale and spd) or not k[2:].isdigit() or int(k[2:]) < min_id:
            continue
        ratio = floor / sale
        if spd >= min_spd and ratio <= max_ratio:
            found.append((spd * sale, k, p, ratio, spd))
    found.sort(reverse=True)
    table(['Item', 'ID', 'Floor', 'Sale avg', '60d hist', 'Floor/avg', 'Sold/day', 'Gold/day'], [
        [tsm.names.get(k, '?'), k[2:], fmt(p['minBuyout']), fmt(p['regionSale']),
         fmt(p.get('historical') or 0), f'{ratio:.2f}', f'{spd:,.0f}', f'{gpd / 10000:,.0f}g']
        for gpd, k, p, ratio, spd in found[:top]
    ])


def resolve(a):
    sv, appdata = a.sv, a.appdata
    if not (sv and appdata):
        if not a.wow:
            sys.exit('pass --wow (WoW _retail_ folder) or --sv and --appdata')
        root = os.path.join(a.wow, 'WTF', 'Account')
        account = a.account
        if not account:
            accts = [d for d in os.listdir(root) if d != 'SavedVariables' and os.path.isdir(os.path.join(root, d))]
            if len(accts) != 1:
                sys.exit(f'pass --account, found: {", ".join(accts)}')
            account = accts[0]
        sv = sv or os.path.join(root, account, 'SavedVariables', 'TradeSkillMaster.lua')
        appdata = appdata or os.path.join(a.wow, 'Interface', 'AddOns', 'TradeSkillMaster_AppHelper', 'AppData.lua')
    return sv, appdata


def main():
    ap = argparse.ArgumentParser(description='TSM ledger + market check (read-only)')
    ap.add_argument('--wow', default=DEFAULT_WOW.get(platform.system()), help='WoW _retail_ folder')
    ap.add_argument('--account', help='WTF account folder, auto if only one')
    ap.add_argument('--sv', help='TradeSkillMaster.lua path, overrides --wow')
    ap.add_argument('--appdata', help='AppData.lua path, overrides --wow')
    ap.add_argument('--trades', default='trades.csv')
    ap.add_argument('--region', default='US')
    sub = ap.add_subparsers(dest='cmd', required=True)
    sub.add_parser('ledger')
    m = sub.add_parser('market')
    m.add_argument('--watch', default='watchlist.txt', help='one item name or i:ID per line, # comments')
    s = sub.add_parser('scan')
    s.add_argument('--min-id', type=int, default=235000, help='skip older-expansion items (235000 ~ Midnight)')
    s.add_argument('--min-spd', type=float, default=5000, help='min region sold/day')
    s.add_argument('--max-ratio', type=float, default=0.8, help='max floor / region sale avg')
    s.add_argument('--top', type=int, default=40)
    a = ap.parse_args()

    sv, appdata = resolve(a)
    tsm = TSM(sv)
    app = AppData(appdata, a.region) if a.cmd != 'ledger' else None
    print(f'TSM saved {local(tsm.mtime)}' +
          (f"   prices {local(app.times.get('AUCTIONDB_COMMODITY_DATA', 0))}" if app else '') + '\n')

    ledger = {}
    if os.path.exists(a.trades):
        ledger = build_ledger(load_trades(a.trades, tsm.names), tsm.csv['csvSales'])
    elif a.cmd == 'ledger':
        sys.exit(f'{a.trades} not found')

    if a.cmd == 'ledger':
        cmd_ledger(tsm, ledger)
    elif a.cmd == 'market':
        watch = DEFAULT_WATCH
        if os.path.exists(a.watch):
            with open(a.watch, encoding='utf-8') as f:
                watch = [ln.strip() for ln in f if ln.strip() and not ln.lstrip().startswith('#')]
        cmd_market(tsm, app, ledger, watch)
    else:
        cmd_scan(tsm, app, a.min_id, a.min_spd, a.max_ratio, a.top)


if __name__ == '__main__':
    main()
