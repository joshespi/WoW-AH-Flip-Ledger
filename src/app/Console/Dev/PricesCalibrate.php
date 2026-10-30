<?php

namespace App\Console\Dev;

use App\Support\MarketStats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DEV ONLY (registered in local and testing, never in production; see AppServiceProvider).
 *
 * Compares the metrics we derive from Blizzard snapshots with TSM's own numbers from your local
 * AppData.lua, so formulas and thresholds can be tuned without ever shipping TSM data:
 *
 *   python legacy/dump_appdata.py --appdata ".../AppData.lua" > appdata.json
 *   docker compose cp appdata.json app:/tmp/appdata.json
 *   docker compose exec app php artisan prices:calibrate /tmp/appdata.json
 *
 * Needs a few weeks of snapshots before typical, hist60 and sold/day mean anything.
 */
class PricesCalibrate extends Command
{
    protected $signature = 'prices:calibrate {file : JSON from legacy/dump_appdata.py} {--region=us} {--min-days= : Only compare derived metrics for items with this many days of data (default: ledger.prices.min_days)}';

    protected $description = 'DEV ONLY: compare derived price metrics with TSM AppData numbers from a local dump';

    /** Our column, TSM's field, label. */
    private const PAIRS = [
        ['floor', 'floor', 'floor vs minBuyout', false],
        ['recent', 'recent', 'recent (24h median) vs marketValueRecent', false],
        ['typical', 'sale', 'typical (30d median) vs regionSale', true],
        ['hist60', 'hist', 'hist60 vs historical', true],
        ['sold_per_day_est', 'spd', 'sold/day estimate vs regionSoldPerDay', true],
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        $dump = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($dump) || ! isset($dump['items']) || ! is_array($dump['items'])) {
            $this->error("{$path} is not a dump from legacy/dump_appdata.py.");

            return self::FAILURE;
        }

        $minDays = (int) ($this->option('min-days') ?: MarketStats::minDays());
        $ours = DB::table('item_stats')->where('region', $this->option('region'))->get()->keyBy('item_id');

        $this->line(sprintf('%d items in the TSM dump, %d with our stats. Derived metrics need >= %d days of data.', count($dump['items']), $ours->count(), $minDays));

        if ($ours->isEmpty()) {
            $this->warn('No item_stats yet: fetch some snapshots first (prices:fetch).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf('%-44s %6s %9s %9s %9s %8s %8s', 'metric', 'items', 'ratio p25', 'median', 'ratio p75', 'within20', 'rank corr'));

        foreach (self::PAIRS as [$column, $field, $label, $derived]) {
            $pairs = [];

            foreach ($dump['items'] as $id => $tsm) {
                $row = $ours->get((int) $id);
                $theirs = $tsm[$field] ?? null;
                $mine = $row?->{$column};

                if ($row === null || ! $mine || ! $theirs || ($derived && $row->days_of_data < $minDays)) {
                    continue;
                }

                $pairs[] = [(float) $mine, (float) $theirs];
            }

            if (count($pairs) < 3) {
                $this->line(sprintf('%-44s %6d   not enough overlapping items yet', $label, count($pairs)));

                continue;
            }

            $ratios = array_map(fn ($p) => $p[0] / $p[1], $pairs);
            sort($ratios);
            $within = count(array_filter($ratios, fn ($r) => abs($r - 1) <= 0.2)) / count($ratios);

            $this->line(sprintf(
                '%-44s %6d %9.2f %9.2f %9.2f %7.0f%% %8.2f',
                $label, count($pairs), $this->quantile($ratios, 0.25), $this->quantile($ratios, 0.5), $this->quantile($ratios, 0.75),
                $within * 100, $this->spearman($pairs),
            ));
        }

        $this->newLine();
        $this->line('ratio = ours / TSM. A median near 1.00 with a tight p25..p75 band and rank corr near 1 means the formula tracks TSM.');
        $this->line('Record the findings in PLAN.md (Phase 4) and retune thresholds (falling knife, days to sell) before building Scan.');

        return self::SUCCESS;
    }

    /**
     * @param  list<float>  $sorted
     */
    private function quantile(array $sorted, float $q): float
    {
        $pos = ($sorted === [] ? 0 : count($sorted) - 1) * $q;
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);

        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
    }

    /**
     * Spearman rank correlation of [ours, theirs] pairs (average ranks for ties).
     *
     * @param  list<array{float, float}>  $pairs
     */
    private function spearman(array $pairs): float
    {
        $a = $this->ranks(array_column($pairs, 0));
        $b = $this->ranks(array_column($pairs, 1));
        $n = count($a);
        $ma = array_sum($a) / $n;
        $mb = array_sum($b) / $n;
        $cov = $va = $vb = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $cov += ($a[$i] - $ma) * ($b[$i] - $mb);
            $va += ($a[$i] - $ma) ** 2;
            $vb += ($b[$i] - $mb) ** 2;
        }

        return $va > 0 && $vb > 0 ? $cov / sqrt($va * $vb) : 0.0;
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private function ranks(array $values): array
    {
        $order = array_keys($values);
        usort($order, fn ($x, $y) => $values[$x] <=> $values[$y]);

        $ranks = [];
        for ($i = 0, $n = count($order); $i < $n;) {
            $j = $i;
            while ($j + 1 < $n && $values[$order[$j + 1]] == $values[$order[$i]]) {
                $j++;
            }
            foreach (range($i, $j) as $k) {
                $ranks[$order[$k]] = ($i + $j) / 2 + 1;
            }
            $i = $j + 1;
        }

        ksort($ranks);

        return array_values($ranks);
    }
}
