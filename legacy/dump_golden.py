#!/usr/bin/env python3
"""
Emit golden JSON from the legacy tool, for the PHP parity tests. Imports tsm_ledger.py as-is.

  python dump_golden.py money > money.golden.json
  python dump_golden.py ledger --sv TradeSkillMaster.lua --trades trades.csv > ledger.golden.json
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import tsm_ledger as t  # noqa: E402

FMT_CASES = [
    0, 1, 5, 99, 100, 101, 999, 9999, 10000, 10001, 10099, 12345, 123456789, 40891250000,
    -1, -99, -100, -9999, -10000, -123456789,
    0.5, 1.5, 2.5, 3.5, 99.5, 2.4999, 50.5, 12345.5, 4891.25, 507900.4, 507900.5, 507900.6,
    -0.5, -1.5, -2.5, 1000000.5,
]

PARSE_CASES = [
    '5031.74', '5031g 74s 2c', '2,730g 82s', '2,730g 82s 00c', '0', '1', '1g', '1s', '1c', '5g2c', '3g  7s',
    '  3g  ', '0.00005', '0.00015', '0.00025', '-5', '-0.5', '.5', '5.', '12345.6789', '7,203g 07s',
    '1,234,567g', '50s 79c', '99c', '1.5s', 'abc', '', 'g', '5x', '1g 2g',
]


def money():
    parse = {}
    for case in PARSE_CASES:
        try:
            parse[case] = t.parse_gold(case)
        except ValueError:
            parse[case] = None
    return {'fmt': [[c, t.fmt(c)] for c in FMT_CASES], 'parse_gold': parse}


def ledger(a):
    tsm = t.TSM(a.sv)
    trades = t.load_trades(a.trades, tsm.names)
    led = t.build_ledger(trades, tsm.csv['csvSales'])
    return {
        'names': tsm.names,
        'inventory': dict(tsm.inventory),
        'csv_counts': {k: len(v) for k, v in tsm.csv.items()},
        'trades': trades,
        'ledger': led,
    }


def main():
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest='cmd', required=True)
    sub.add_parser('money')
    lg = sub.add_parser('ledger')
    lg.add_argument('--sv', required=True)
    lg.add_argument('--trades', required=True)
    a = ap.parse_args()
    out = money() if a.cmd == 'money' else ledger(a)
    json.dump(out, sys.stdout, sort_keys=True, indent=1, ensure_ascii=False)
    print()


if __name__ == '__main__':
    main()
