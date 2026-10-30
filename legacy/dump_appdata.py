#!/usr/bin/env python3
"""
DEV ONLY. Dumps your local TSM AppData.lua commodity prices to JSON so `php artisan prices:calibrate`
can compare them with the metrics derived from Blizzard snapshots. The output is TSM's data: keep it
on your machine and never commit or deploy it.

  python dump_appdata.py --appdata "<WoW>/_retail_/Interface/AddOns/TradeSkillMaster_AppHelper/AppData.lua" > appdata.json
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import tsm_ledger as t  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--appdata', required=True)
    ap.add_argument('--region', default='US')
    a = ap.parse_args()

    app = t.AppData(a.appdata, a.region)
    out = {}
    for key in sorted(app.commodities(), key=lambda k: int(k[2:]) if k[2:].isdigit() else 0):
        if not key[2:].isdigit():
            continue
        p = app.item(key)
        out[key[2:]] = {
            'floor': p.get('minBuyout'),
            'recent': p.get('marketValueRecent'),
            'sale': p.get('regionSale'),
            'hist': p.get('historical'),
            'spd': p.get('regionSoldPerDay'),
        }

    json.dump({'downloaded': app.times.get('AUCTIONDB_COMMODITY_DATA'), 'items': out}, sys.stdout)
    print()


if __name__ == '__main__':
    main()
