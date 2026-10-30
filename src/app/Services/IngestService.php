<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\User;
use App\Support\Bounds;
use App\Support\Bulk;
use App\Support\ItemName;
use App\Support\PayloadDecoder;
use App\Support\Utc;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns an uploaded TradeSkillMaster.lua into rows for one user. The raw file is parsed in
 * memory and dropped; only normalized rows, an upload record and gap-filling item names are
 * kept. Both the uploader endpoint and the manual upload page call this.
 *
 * The file is cumulative, so every upload re-sends history. Inserts are INSERT IGNORE on a
 * unique (user_id, dedupe_key), which makes re-uploads add nothing. A parse failure writes
 * only the failed upload record; the data tables are written in one transaction.
 */
class IngestService
{
    public function __construct(private readonly TsmParser $parser) {}

    /**
     * @param  string  $payload  the file as received: plain, or gzipped (detected by magic bytes)
     */
    public function ingest(
        User $user,
        string $payload,
        string $source,
        ?int $fileMtime = null,
        ?string $clientVersion = null,
        ?string $wowAccount = null,
    ): Upload {
        $received = time();
        $mtime = $this->plausibleMtime($fileMtime, $received);

        $base = [
            'user_id' => $user->id,
            'source' => $source,
            'client_version' => $this->label($clientVersion, 32),
            'wow_account' => $this->label($wowAccount, 64),
            'file_mtime' => $mtime === null ? null : Utc::sql($mtime),
            'received_at' => Utc::sql($received),
            'bytes' => strlen($payload),
        ];

        try {
            $contents = PayloadDecoder::decode($payload);
            $base['bytes'] = strlen($contents);
            $data = $this->parser->parse($contents);
        } catch (TsmParseException $e) {
            return $this->failed($base, $e->getMessage());
        }

        // Up to 48 MB when it was gzipped; nothing needs it past the parse.
        unset($contents);

        $lock = Cache::lock('ingest:'.$user->id, 120);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            return $this->failed($base, 'Another upload for your account is still being processed. Try again in a minute.', retryable: true);
        }

        try {
            return DB::transaction(fn () => Upload::create(
                $base + ['status' => Upload::STATUS_OK, 'counts' => $this->store($user, $data, $mtime ?? $received, $mtime !== null)],
            ));
        } catch (Throwable $e) {
            report($e);

            return $this->failed($base, 'Could not save your data. Nothing was changed.', retryable: true);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  bool  $trustedTime  $takenAt is the file's mtime, not just when we received it
     * @return array<string, mixed>
     */
    private function store(User $user, TsmData $data, int $takenAt, bool $trustedTime): array
    {
        $skipped = 0;
        $counts = [];

        $timed = static fn (array $r, array $c): array => ['at' => $c['at']];

        $sale = static function (array $r, array $c): ?array {
            $price = Bounds::count($r['price'] ?? null, Bounds::MAX_PRICE);
            $source = $r['source'] ?? '';

            return $price === null || ! preg_match('/^[A-Za-z]{1,16}$/D', $source) ? null
                : ['unit_price_copper' => $price, 'sold_at' => $c['at'], 'source' => $source];
        };

        $buy = static function (array $r, array $c): ?array {
            $price = Bounds::count($r['price'] ?? null, Bounds::MAX_PRICE);

            return $price === null || ($cost = $c['qty'] * $price) > Bounds::MAX_COST ? null
                : ['cost_copper' => $cost, 'at' => $c['at'], 'status' => ReconcileService::PENDING];
        };

        // Auction buys only: vendor purchases are never flips and are ignored without counting.
        $auctionBuys = array_filter($data->rows('csvBuys'), static fn (array $r): bool => ($r['source'] ?? '') === TsmData::SOURCE_AUCTION);

        // Normalized and inserted one table at a time, so peak memory is the largest table, not all four.
        $sets = [
            'sales' => ['tsm_sales', 'sale', 'dedupe_key', $data->rows('csvSales'), $sale],
            'expired' => ['tsm_expired', 'expired', 'dedupe_key', $data->rows('csvExpired'), $timed],
            'cancelled' => ['tsm_cancelled', 'cancelled', 'dedupe_key', $data->rows('csvCancelled'), $timed],
            'buy_candidates' => ['tsm_buy_candidates', 'buy', 'tsm_ref', $auctionBuys, $buy],
        ];

        $touched = [];

        foreach ($sets as $name => [$table, $kind, $keyColumn, $source, $extra]) {
            $rows = $this->rows($user, $kind, $keyColumn, $source, $extra, $skipped);
            $counts[$name] = ['seen' => count($rows), 'new' => Bulk::insertOrIgnore($table, $rows)];
            $touched += array_fill_keys(array_column($rows, 'item_id'), true);
        }

        $counts['inventory'] = $this->replaceInventory($user, $data->inventory, $takenAt, $trustedTime, $touched);
        $counts['names_added'] = $this->fillNames($data->names, array_keys($touched));
        $counts['skipped'] = $skipped;

        return $counts;
    }

    /**
     * The valid rows of one TSM table: the columns every table shares (user, realm, item, qty,
     * dedupe key) plus what $extra adds. $extra returns null to reject a row; rejected rows
     * count as skipped.
     *
     * @param  array<array<string, string>>  $rows
     * @param  callable(array<string, string>, array{realm: string, item: int, qty: int, at: string}): ?array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function rows(User $user, string $kind, string $keyColumn, array $rows, callable $extra, int &$skipped): array
    {
        $out = [];
        $occurrence = [];

        foreach ($rows as $r) {
            $c = $this->common($r);
            $more = $c === null ? null : $extra($r, $c);

            if ($more === null) {
                $skipped++;

                continue;
            }

            $out[] = [
                'user_id' => $user->id,
                'realm' => $c['realm'],
                'item_id' => $c['item'],
                'qty' => $c['qty'],
                $keyColumn => $this->key($kind, $r, $occurrence),
            ] + $more;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $r
     * @return array{realm: string, item: int, qty: int, at: string}|null
     */
    private function common(array $r): ?array
    {
        $item = ItemName::numericId(ItemName::baseId($r['itemString'] ?? ''));
        $qty = Bounds::count($r['quantity'] ?? null, Bounds::MAX_QTY);
        $time = Bounds::count($r['time'] ?? null);
        $realm = substr($r['realm'] ?? '', 0, 64);

        if ($item === null || ! Bounds::validItemId($item) || $qty === null || $qty < 1 || $realm === ''
            || $time === null || ! Bounds::plausibleTime($time)) {
            return null;
        }

        return ['realm' => $realm, 'item' => $item, 'qty' => $qty, 'at' => Utc::sql($time)];
    }

    /**
     * Stable row identity plus how many identical rows came before it in this file, so two
     * genuinely identical sales keep distinct keys and a re-upload reproduces the same keys.
     *
     * @param  array<string, string>  $r
     * @param  array<string, int>  $occurrence
     */
    private function key(string $kind, array $r, array &$occurrence): string
    {
        $base = sha1(implode('|', [
            $kind, $r['realm'] ?? '', $r['time'] ?? '', $r['itemString'] ?? '',
            $r['quantity'] ?? '', $r['price'] ?? '', $r['source'] ?? '',
        ]));

        $occurrence[$base] = ($occurrence[$base] ?? -1) + 1;

        return sha1($base.'#'.$occurrence[$base]);
    }

    /**
     * Latest snapshot only. A file older than the snapshot we hold doesn't overwrite it, and a
     * file with no inventory at all doesn't wipe it.
     *
     * @param  array<string, int>  $inventory
     * @param  array<int, true>  $touched
     * @return array{items: int, applied: bool}
     */
    private function replaceInventory(User $user, array $inventory, int $takenAt, bool $trustedTime, array &$touched): array
    {
        $rows = [];
        $taken = Utc::sql($takenAt);

        foreach ($inventory as $baseId => $qty) {
            $id = ItemName::numericId($baseId);

            if ($id === null || ! Bounds::validItemId($id) || $qty < 1 || $qty > Bounds::MAX_INVENTORY) {
                continue;
            }

            $touched[$id] = true;
            $rows[] = ['user_id' => $user->id, 'item_id' => $id, 'qty' => $qty, 'taken_at' => $taken];
        }

        $latest = DB::table('inventory')->ownedBy($user)->max('taken_at');

        // Without a trustworthy file time, "now" must not let a stale file replace a newer snapshot.
        if ($rows === [] || ($latest !== null && ($taken < $latest || ! $trustedTime))) {
            return ['items' => count($rows), 'applied' => false];
        }

        DB::table('inventory')->ownedBy($user)->delete();
        Bulk::insertOrIgnore('inventory', $rows);

        return ['items' => count($rows), 'applied' => true];
    }

    /**
     * Fill names only for items this user's data mentions, never override an existing name
     * (Blizzard's wins later), and sanitize because uploads are untrusted. The catalog is shared
     * and trade entry resolves items by name, so an upload can only name commodities the price
     * fetch already created (never add rows for arbitrary ids), and only while unnamed.
     *
     * @param  array<string, string>  $names
     * @param  list<int>  $ids
     */
    private function fillNames(array $names, array $ids): int
    {
        $now = Utc::sql();
        $clean = [];

        foreach ($ids as $id) {
            $name = isset($names['i:'.$id]) ? ItemName::sanitize($names['i:'.$id]) : null;

            if ($name !== null) {
                $clean[$id] = $name;
            }
        }

        $added = 0;

        foreach (array_chunk($clean, Bulk::CHUNK, preserve_keys: true) as $chunk) {
            // Items the price fetch created have a row but no name yet: fill those in too, in one
            // statement per chunk.
            $cases = $bindings = [];

            foreach ($chunk as $id => $name) {
                $cases[] = 'when ? then ?';
                array_push($bindings, $id, $name);
            }

            $in = implode(',', array_fill(0, count($chunk), '?'));
            $added += DB::update(
                'update items set name = case id '.implode(' ', $cases)." end, name_source = 'tsm', updated_at = ? where id in ({$in}) and name is null and is_commodity = 1",
                [...$bindings, $now, ...array_keys($chunk)],
            );
        }

        return $added;
    }

    /** A file's mtime is trusted less than row times: one day of clock skew, not two. */
    private function plausibleMtime(?int $mtime, int $now): ?int
    {
        return $mtime !== null && Bounds::plausibleTime($mtime, 86400, $now) ? $mtime : null;
    }

    private function label(?string $v, int $max): ?string
    {
        return $v !== null && preg_match('/^[A-Za-z0-9._-]{1,'.$max.'}$/D', $v) ? $v : null;
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function failed(array $base, string $message, bool $retryable = false): Upload
    {
        $upload = Upload::create($base + ['status' => Upload::STATUS_FAILED, 'error' => mb_substr($message, 0, 500)]);
        $upload->retryable = $retryable;

        return $upload;
    }
}
