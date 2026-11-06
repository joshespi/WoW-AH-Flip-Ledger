<?php

namespace App\Services;

use App\Models\Trade;
use App\Models\User;
use App\Support\Bounds;
use App\Support\Bulk;
use App\Support\Game;
use App\Support\ItemName;
use App\Support\Utc;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Imports a trades CSV (`time,item,qty,cost,note`) as the user's buys. All-or-nothing: every
 * row is validated before anything is written, so a bad file never leaves a half-imported
 * ledger. Re-importing the same file adds nothing (see trades.import_key).
 *
 * A row's identity is time, item, quantity and cost (the note is not part of it), plus how many
 * identical rows came before it in the file. So two identical lines in one file are two lots,
 * and a later file that lists the same lot twice adds exactly one more than a file that listed
 * it once.
 */
class TradesImportService
{
    public const MAX_BYTES = 1024 * 1024;

    public const MAX_ROWS = 5000;

    /** Shown on the import page and offered as a download; a test keeps it importable. */
    public const TEMPLATE = <<<'CSV'
        time,item,qty,cost,note
        2026-10-12T10:51-06:00,i:238371,625,25231.25,Arcane Wyrmfish
        2026-10-24T09:34-06:00,i:242639,5351,"2,730g 82s",Practically Pork
        CSV;

    public function __construct(private TradesCsvParser $parser) {}

    /**
     * @return array{new: int, duplicates: int}
     *
     * @throws InvalidArgumentException with a user-readable message
     */
    public function import(User $user, string $csv, string $game = Game::RETAIL): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new InvalidArgumentException('That file is too large. Trade CSVs are limited to '.Number::fileSize(self::MAX_BYTES).'.');
        }

        // Excel's "CSV UTF-8" export starts with a BOM, which would corrupt the first header.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

        $trades = $this->parser->parse($csv, $this->names(), new DateTimeZone($user->timezone));

        if (count($trades) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Too many rows. Import at most '.number_format(self::MAX_ROWS).' trades at a time.');
        }

        $rows = [];
        $occurrence = [];
        $now = Utc::sql();

        foreach ($trades as $t) {
            $id = ItemName::numericId($t['item']);
            $problem = $id === null
                ? "only items (i:ID) can be traded, got \"{$t['item']}\""
                : Bounds::tradeProblem($id, $t['qty'], $t['cost'], $t['ts']);

            if ($problem !== null) {
                throw new InvalidArgumentException("line {$t['line']}: {$problem}.");
            }

            $base = $t['ts'].'|'.$t['item'].'|'.$t['qty'].'|'.$t['cost'];
            $n = $occurrence[$base] = ($occurrence[$base] ?? -1) + 1;

            $rows[] = [
                'user_id' => $user->id,
                'game' => $game,
                'item_id' => $id,
                'traded_at' => Utc::sql($t['ts']),
                'qty' => $t['qty'],
                'cost_copper' => $t['cost'],
                'side' => Trade::SIDE_BUY,
                'note' => Bounds::note($t['note']),
                'source' => 'csv',
                'import_key' => sha1(Game::keyPrefix($game).$base.'|'.$n),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $new = DB::transaction(fn () => Bulk::insertOrIgnore('trades', $rows));

        return ['new' => $new, 'duplicates' => count($rows) - $new];
    }

    /**
     * Names the CSV may use instead of ids, as the parser's base-id map.
     *
     * @return array<string, string>
     */
    private function names(): array
    {
        $names = [];

        foreach (DB::table('items')->whereNotNull('name')->select('id', 'name')->cursor() as $item) {
            $names['i:'.(int) $item->id] = $item->name;
        }

        return $names;
    }
}
