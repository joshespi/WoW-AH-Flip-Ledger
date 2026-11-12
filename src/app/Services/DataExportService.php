<?php

namespace App\Services;

use App\Models\Trade;
use App\Models\User;
use App\Support\Csv;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Everything the app holds about a user, as a zip of CSVs: the account's settings, trades (in
 * the import format, so it can be loaded back), TSM sales, expirations and cancellations, TSM
 * buy suggestions, the watchlist and the upload history; per-game rows carry their game. Never
 * the password, tokens, or the shared price data. Times are ISO 8601 in the user's timezone;
 * text that starts with a spreadsheet formula character is prefixed with an apostrophe.
 */
class DataExportService
{
    /** The trades import format's columns. */
    public const TRADE_COLUMNS = ['time', 'item', 'qty', 'cost', 'note'];

    /**
     * A buy as the trades import format's columns, so an export loads straight back in.
     *
     * @return list<mixed>
     */
    public static function tradeFields(User $user, object $t): array
    {
        return [self::iso($user, $t->traded_at), 'i:'.(int) $t->item_id, $t->qty, Money::fmt($t->cost_copper), $t->note];
    }

    private static function iso(User $user, mixed $utc): string
    {
        return $utc === null ? '' : $user->local($utc)->format('Y-m-d\TH:i:sP');
    }

    /**
     * Writes the zip to a temp file and returns its path; the caller deletes it.
     *
     * @throws RuntimeException
     */
    public function build(User $user): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'wfl-export-');
        $parts = [];
        $zip = new ZipArchive;

        if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export.');
        }

        try {
            $iso = fn (mixed $utc) => self::iso($user, $utc);
            $sources = [
                'trades.csv' => [
                    [...self::TRADE_COLUMNS, 'item_name', 'source', 'game'],
                    $this->named($user, 'trades', 'traded_at', ['traded_at', 'item_id', 'qty', 'cost_copper', 'note', 'source', 'game'])
                        ->where('trades.side', Trade::SIDE_BUY)->cursor(),
                    fn ($r) => [...self::tradeFields($user, $r), $r->item_name, $r->source, $r->game],
                ],
                'corrections.csv' => [
                    ['time', 'item', 'qty_removed', 'kind', 'note', 'item_name', 'game', 'proceeds_copper'],
                    $this->named($user, 'trades', 'traded_at', ['traded_at', 'item_id', 'qty', 'side', 'note', 'game', 'cost_copper'])
                        ->whereIn('trades.side', Trade::CORRECTIONS)->cursor(),
                    fn ($r) => [$iso($r->traded_at), 'i:'.$r->item_id, $r->qty, $r->side, $r->note, $r->item_name, $r->game, Trade::proceeds($r->side, (int) $r->cost_copper)],
                ],
                'sales.csv' => [
                    ['time', 'item', 'item_name', 'qty', 'unit_price_after_cut_copper', 'realm', 'source', 'game'],
                    $this->named($user, 'tsm_sales', 'sold_at', ['sold_at', 'item_id', 'qty', 'unit_price_copper', 'realm', 'source', 'game'])->cursor(),
                    fn ($r) => [$iso($r->sold_at), 'i:'.$r->item_id, $r->item_name, $r->qty, $r->unit_price_copper, $r->realm, $r->source, $r->game],
                ],
                'expired.csv' => $this->listing($user, 'tsm_expired', $iso),
                'cancelled.csv' => $this->listing($user, 'tsm_cancelled', $iso),
                'buy_suggestions.csv' => [
                    ['time', 'item', 'item_name', 'qty', 'cost_copper', 'status', 'realm', 'game'],
                    $this->named($user, 'tsm_buy_candidates', 'at', ['at', 'item_id', 'qty', 'cost_copper', 'status', 'realm', 'game'])->cursor(),
                    fn ($r) => [$iso($r->at), 'i:'.$r->item_id, $r->item_name, $r->qty, $r->cost_copper, $r->status, $r->realm, $r->game],
                ],
                'watchlist.csv' => [
                    ['item', 'item_name', 'group', 'target_buy_copper', 'target_sell_copper', 'notes'],
                    $this->named($user, 'watchlist_items', 'sort', ['item_id', 'group_name', 'target_buy_copper', 'target_sell_copper', 'notes'])->cursor(),
                    fn ($r) => ['i:'.$r->item_id, $r->item_name, $r->group_name, $r->target_buy_copper, $r->target_sell_copper, $r->notes],
                ],
                'uploads.csv' => [
                    ['received_at', 'source', 'status', 'bytes', 'client_version', 'error', 'game'],
                    DB::table('uploads')->ownedBy($user)->orderBy('received_at')->orderBy('id')
                        ->select('received_at', 'source', 'status', 'bytes', 'client_version', 'error', 'game')->cursor(),
                    fn ($r) => [$iso($r->received_at), $r->source, $r->status, $r->bytes, $r->client_version, $r->error, $r->game],
                ],
                'account.csv' => [
                    ['field', 'value'],
                    [
                        ['name', $user->name], ['email', $user->email], ['timezone', $user->timezone], ['region', $user->region],
                        ['target_margin_pct', $user->target_margin_pct], ['market_share_pct', $user->market_share_pct],
                        ['stale_minutes', $user->stale_minutes], ['created', $iso($user->created_at)],
                        ['scoreboard_opt_in', $user->scoreboard_opt_in ? 1 : 0], ['scoreboard_nickname', $user->scoreboard_nickname],
                    ],
                    fn ($r) => $r,
                ],
                'activity_days.csv' => [
                    ['day'],
                    DB::table('user_activity_days')->ownedBy($user)->orderBy('day')->select('day')->cursor(),
                    fn ($r) => [substr((string) $r->day, 0, 10)],
                ],
            ];

            foreach ($sources as $file => [$header, $rows, $map]) {
                $path = tempnam(sys_get_temp_dir(), 'wfl-csv-');
                $parts[] = $path;
                $out = fopen($path, 'w');
                Csv::write($out, $header, $rows, $map);
                fclose($out);
                $zip->addFile($path, $file);
            }

            $zip->addFromString('README.txt', $this->readme());
            $zip->close();
        } catch (\Throwable $e) {
            @unlink($zipPath);
            throw $e;
        } finally {
            foreach ($parts as $p) {
                @unlink($p);
            }
        }

        return $zipPath;
    }

    /**
     * $table's rows for $user with the catalog name as `item_name`, ordered by $order then id.
     *
     * @param  list<string>  $columns  of $table
     */
    private function named(User $user, string $table, string $order, array $columns): Builder
    {
        return DB::table($table)->leftJoin('items', 'items.id', '=', "{$table}.item_id")
            ->ownedBy($user, "{$table}.user_id")->orderBy("{$table}.{$order}")->orderBy("{$table}.id")
            ->select([...array_map(fn (string $c) => "{$table}.{$c}", $columns), 'items.name as item_name']);
    }

    /** @return array{0: list<string>, 1: iterable<object>, 2: callable} */
    private function listing(User $user, string $table, callable $iso): array
    {
        return [
            ['time', 'item', 'item_name', 'qty', 'realm', 'game'],
            $this->named($user, $table, 'at', ['at', 'item_id', 'qty', 'realm', 'game'])->cursor(),
            fn ($r) => [$iso($r->at), 'i:'.$r->item_id, $r->item_name, $r->qty, $r->realm, $r->game],
        ];
    }

    private function readme(): string
    {
        $activityDays = config('ledger.stats.activity_days');

        return <<<TXT
            WoW Flip Ledger data export

            trades.csv           Your buys, in the trades import format (time,item,qty,cost,note);
                                 the extra columns are ignored on import. cost is the lot total.
                                 The import loads into the game you have selected, so split
                                 the file by its game column first if you play more than one.
            corrections.csv      Stock you removed from a position: kind "adjust" (the ledger was
                                 wrong, no profit or loss), "writeoff" (booked as a loss) or
                                 "vendor" (sold to a vendor; proceeds_copper is the total received).
            sales.csv            Auction sales read from your TradeSkillMaster data. Prices are
                                 copper per unit, after the 5% auction house cut.
            expired.csv          Expired auctions.
            cancelled.csv        Cancelled auctions.
            buy_suggestions.csv  TSM's own buy log rows, kept only as suggestions to reconcile.
            watchlist.csv        Your watchlist and targets (copper per unit).
            uploads.csv          Your upload history.
            account.csv          Your account settings, including whether you are on the public
                                 scoreboard and your nickname. Passwords and tokens are never exported.
            activity_days.csv    The days (UTC) you used the site or uploaded data, kept {$activityDays} days.

            Every file but watchlist.csv, account.csv and activity_days.csv has a game column (retail, forever
            or classic): each game is a separate ledger.
            Times are ISO 8601 in your account timezone. 1 gold = 10,000 copper.
            Text starting with = + - or @ is prefixed with an apostrophe so spreadsheets
            do not run it as a formula.
            TXT;
    }
}
