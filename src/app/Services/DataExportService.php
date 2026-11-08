<?php

namespace App\Services;

use App\Models\Trade;
use App\Models\User;
use App\Support\Csv;
use App\Support\Money;
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
            $name = 'items.name as item_name';
            $iso = fn (mixed $utc) => self::iso($user, $utc);
            $sources = [
                'trades.csv' => [
                    [...self::TRADE_COLUMNS, 'item_name', 'source', 'game'],
                    DB::table('trades')->leftJoin('items', 'items.id', '=', 'trades.item_id')
                        ->ownedBy($user, 'trades.user_id')->where('trades.side', Trade::SIDE_BUY)->orderBy('trades.traded_at')->orderBy('trades.id')
                        ->select('trades.traded_at', 'trades.item_id', 'trades.qty', 'trades.cost_copper', 'trades.note', $name, 'trades.source', 'trades.game')->cursor(),
                    fn ($r) => [...self::tradeFields($user, $r), $r->item_name, $r->source, $r->game],
                ],
                'corrections.csv' => [
                    ['time', 'item', 'qty_removed', 'kind', 'note', 'item_name', 'game'],
                    DB::table('trades')->leftJoin('items', 'items.id', '=', 'trades.item_id')
                        ->ownedBy($user, 'trades.user_id')->whereIn('trades.side', Trade::CORRECTIONS)->orderBy('trades.traded_at')->orderBy('trades.id')
                        ->select('trades.traded_at', 'trades.item_id', 'trades.qty', 'trades.side', 'trades.note', $name, 'trades.game')->cursor(),
                    fn ($r) => [$iso($r->traded_at), 'i:'.$r->item_id, $r->qty, $r->side, $r->note, $r->item_name, $r->game],
                ],
                'sales.csv' => [
                    ['time', 'item', 'item_name', 'qty', 'unit_price_after_cut_copper', 'realm', 'source', 'game'],
                    DB::table('tsm_sales')->leftJoin('items', 'items.id', '=', 'tsm_sales.item_id')
                        ->ownedBy($user, 'tsm_sales.user_id')->orderBy('tsm_sales.sold_at')->orderBy('tsm_sales.id')
                        ->select('tsm_sales.sold_at', 'tsm_sales.item_id', $name, 'tsm_sales.qty', 'tsm_sales.unit_price_copper', 'tsm_sales.realm', 'tsm_sales.source', 'tsm_sales.game')->cursor(),
                    fn ($r) => [$iso($r->sold_at), 'i:'.$r->item_id, $r->item_name, $r->qty, $r->unit_price_copper, $r->realm, $r->source, $r->game],
                ],
                'expired.csv' => $this->listing($user, 'tsm_expired', $iso),
                'cancelled.csv' => $this->listing($user, 'tsm_cancelled', $iso),
                'buy_suggestions.csv' => [
                    ['time', 'item', 'item_name', 'qty', 'cost_copper', 'status', 'realm', 'game'],
                    DB::table('tsm_buy_candidates')->leftJoin('items', 'items.id', '=', 'tsm_buy_candidates.item_id')
                        ->ownedBy($user, 'tsm_buy_candidates.user_id')->orderBy('tsm_buy_candidates.at')->orderBy('tsm_buy_candidates.id')
                        ->select('tsm_buy_candidates.at', 'tsm_buy_candidates.item_id', $name, 'tsm_buy_candidates.qty', 'tsm_buy_candidates.cost_copper', 'tsm_buy_candidates.status', 'tsm_buy_candidates.realm', 'tsm_buy_candidates.game')->cursor(),
                    fn ($r) => [$iso($r->at), 'i:'.$r->item_id, $r->item_name, $r->qty, $r->cost_copper, $r->status, $r->realm, $r->game],
                ],
                'watchlist.csv' => [
                    ['item', 'item_name', 'group', 'target_buy_copper', 'target_sell_copper', 'notes'],
                    DB::table('watchlist_items')->leftJoin('items', 'items.id', '=', 'watchlist_items.item_id')
                        ->ownedBy($user, 'watchlist_items.user_id')->orderBy('watchlist_items.sort')->orderBy('watchlist_items.id')
                        ->select('watchlist_items.item_id', $name, 'watchlist_items.group_name', 'watchlist_items.target_buy_copper', 'watchlist_items.target_sell_copper', 'watchlist_items.notes')->cursor(),
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
                Csv::put($out, $header);

                foreach ($rows as $row) {
                    Csv::put($out, $map($row));
                }

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

    /** @return array{0: list<string>, 1: iterable<object>, 2: callable} */
    private function listing(User $user, string $table, callable $iso): array
    {
        return [
            ['time', 'item', 'item_name', 'qty', 'realm', 'game'],
            DB::table($table)->leftJoin('items', 'items.id', '=', "{$table}.item_id")
                ->ownedBy($user, "{$table}.user_id")->orderBy("{$table}.at")->orderBy("{$table}.id")
                ->select("{$table}.at", "{$table}.item_id", 'items.name as item_name', "{$table}.qty", "{$table}.realm", "{$table}.game")->cursor(),
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
                                 wrong, no profit or loss) or "writeoff" (booked as a loss).
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
