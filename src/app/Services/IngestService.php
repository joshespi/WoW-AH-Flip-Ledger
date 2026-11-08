<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\User;
use App\Support\Bounds;
use App\Support\Bulk;
use App\Support\Game;
use App\Support\ItemName;
use App\Support\PayloadDecoder;
use App\Support\TsmSpan;
use App\Support\Utc;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns an uploaded TradeSkillMaster.lua (or the companion addon's WowFlipLedger.lua, detected by
 * its first line) into rows for one user. The raw file is parsed in
 * memory and dropped; only normalized rows, an upload record and gap-filling item names are
 * kept. Both the uploader endpoint and the manual upload page call this.
 *
 * The file is cumulative, so every upload re-sends history. Inserts are INSERT IGNORE on a
 * unique (user_id, dedupe_key), which makes re-uploads add nothing. A parse failure writes
 * only the failed upload record; the data tables are written in one transaction.
 *
 * TSM wins where it has data (TsmSpan): addon rows inside the span TSM has covered are not
 * stored, and a TSM upload that widens it removes addon rows now inside it. Outside that span
 * the addon's rows stand.
 */
class IngestService
{
    /** Every log table ingest writes, with its time column. Each row carries `upload_id` (see UploadService::undo). */
    public const LOG_TABLES = ['tsm_sales' => 'sold_at', 'tsm_expired' => 'at', 'tsm_cancelled' => 'at', 'tsm_buy_candidates' => 'at'];

    public function __construct(
        private readonly TsmParser $parser,
        private readonly AddonParser $addon,
        private readonly UploadGameCheck $games,
    ) {}

    /** One writer of a user's uploaded data at a time: ingest and undo both hold it. */
    public static function lock(User $user): Lock
    {
        return Cache::lock('ingest:'.$user->id, 120);
    }

    /**
     * @param  string  $payload  the file as received: plain, or gzipped (detected by magic bytes)
     * @param  bool  $checkRealms  refuse a file whose realms point at another game (manual uploads; see UploadGameCheck)
     */
    public function ingest(
        User $user,
        string $payload,
        string $source,
        ?int $fileMtime = null,
        ?string $clientVersion = null,
        ?string $wowAccount = null,
        string $game = Game::RETAIL,
        bool $checkRealms = false,
    ): Upload {
        $received = time();
        $mtime = $this->plausibleMtime($fileMtime, $received);

        $base = [
            'user_id' => $user->id,
            'game' => $game,
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
            $data = AddonParser::looksLike($contents) ? $this->addon->parse($contents) : $this->parser->parse($contents);
        } catch (TsmParseException $e) {
            return $this->failed($base, $e->getMessage());
        }

        // Up to 48 MB when it was gzipped; nothing needs it past the parse.
        unset($contents);

        if (($problem = $this->games->problem($user, $game, $data, $checkRealms)) !== null) {
            $upload = $this->failed($base, $problem[0]);
            $upload->wrongGameConfirmable = $problem[1];

            return $upload;
        }

        $lock = self::lock($user);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            return $this->failed($base, 'Another upload for your account is still being processed. Try again in a minute.', retryable: true);
        }

        try {
            return DB::transaction(function () use ($base, $user, $game, $data, $mtime, $received) {
                // Created first so the rows it inserts can carry its id (see UploadService::undo).
                $upload = Upload::create($base + ['status' => Upload::STATUS_OK]);
                $upload->update(['counts' => $this->store($user, $game, TsmSpan::account($base['wow_account']), $data, $mtime ?? $received, $mtime !== null, $upload->id)]);

                return $upload;
            });
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
    private function store(User $user, string $game, string $account, TsmData $data, int $takenAt, bool $trustedTime, int $uploadId): array
    {
        $skipped = $covered = 0;
        $counts = [];
        $addon = $data->origin === TsmData::ORIGIN_ADDON;
        $spans = $addon ? TsmSpan::covering($user, $game, $account) : [];
        // [earliest, latest] time of this TSM file's rows.
        $seen = null;
        $base = ['user_id' => $user->id, 'game' => $game, 'origin' => $data->origin, 'upload_id' => $uploadId, 'account' => $account];
        // Keeps a non-retail row's key apart from an identical retail row's, and an addon row's from a TSM row's.
        $keyPrefix = Game::keyPrefix($game).($addon ? $data->origin.':' : '');

        $timed = static fn (array $r, array $c): array => ['at' => $c['at']];

        $sale = static function (array $r, array $c): ?array {
            $price = Bounds::count($r['price'] ?? null, Bounds::MAX_PRICE);
            $source = $r['source'] ?? '';

            return $price === null || ! preg_match('/^[A-Za-z]{1,16}$/D', $source) ? null
                : ['unit_price_copper' => $price, 'sold_at' => $c['at'], 'source' => $source];
        };

        $buy = static function (array $r, array $c): ?array {
            $price = Bounds::count($r['price'] ?? null, Bounds::MAX_PRICE);

            // The addon knows what a purchase really cost; TSM's rows only give a unit price.
            $cost = isset($r['total']) ? Bounds::count($r['total'], Bounds::MAX_COST) : ($price === null ? null : $c['qty'] * $price);

            return $price === null || $cost === null || $cost > Bounds::MAX_COST ? null
                : ['cost_copper' => $cost, 'at' => $c['at'], 'status' => ReconcileService::PENDING];
        };

        // Auction buys only: vendor purchases are never flips and are ignored without counting.
        $auctionBuys = array_filter($data->rows('csvBuys'), static fn (array $r): bool => ($r['source'] ?? '') === TsmData::SOURCE_AUCTION);

        // Normalized and inserted one table at a time, so peak memory is the largest table, not all four.
        $sets = [
            'sales' => ['tsm_sales', 'sale', 'dedupe_key', $this->resolveSaleNames($data->rows('csvSales'), $game), $sale],
            'expired' => ['tsm_expired', 'expired', 'dedupe_key', $data->rows('csvExpired'), $timed],
            'cancelled' => ['tsm_cancelled', 'cancelled', 'dedupe_key', $data->rows('csvCancelled'), $timed],
            'buy_candidates' => ['tsm_buy_candidates', 'buy', 'tsm_ref', $auctionBuys, $buy],
        ];

        $touched = [];

        foreach ($sets as $name => [$table, $kind, $keyColumn, $source, $extra]) {
            $time = self::LOG_TABLES[$table];
            $rows = $this->rows($base, $keyPrefix.$kind, $keyColumn, $source, $extra, $skipped);

            if ($spans !== []) {
                // TSM already covers this stretch; its rows are the ones we trust there.
                $kept = array_values(array_filter($rows, fn (array $r): bool => ! TsmSpan::covers($spans, $r[$time])));
                $covered += count($rows) - count($kept);
                $rows = $kept;
            }

            if (! $addon && $rows !== []) {
                $times = array_column($rows, $time);
                $seen = [min($seen[0] ?? $times[0], ...$times), max($seen[1] ?? $times[0], ...$times)];
            }

            $counts[$name] = ['seen' => count($rows), 'new' => Bulk::insertOrIgnore($table, $rows)];
            $touched += array_fill_keys(array_column($rows, 'item_id'), true);
        }

        if ($addon) {
            $counts['covered_by_tsm'] = $covered;
        } else {
            // The span ends at the file's mtime only when we have one; "received now" would claim
            // time an old file never covered and delete the addon's rows there.
            $to = $trustedTime ? Utc::sql($takenAt) : $seen[1] ?? null;
            $counts['addon_replaced'] = $to === null ? 0 : TsmSpan::widen($user, $game, $account, min($seen[0] ?? $to, $to), $to);
        }

        // The addon's snapshot is as of its last save, which is the time that matters here.
        if ($data->takenAt !== null) {
            $takenAt = $data->takenAt;
            $trustedTime = true;
        }

        $counts['inventory'] = $this->replaceInventory($user, $game, $data->inventory, $takenAt, $trustedTime, $touched, $addon);
        $counts['names_added'] = Game::hasMarket($game) ? $this->fillNames($data->names, array_keys($touched)) : 0;
        $counts['skipped'] = $skipped;

        $user->enableGame($game);

        return $counts;
    }

    /**
     * The valid rows of one TSM table: $base, the columns every table shares (realm, item, qty,
     * dedupe key) and what $extra adds. $extra returns null to reject a row; rejected rows
     * count as skipped.
     *
     * @param  array<string, mixed>  $base
     * @param  array<array<string, string>>  $rows
     * @param  callable(array<string, string>, array{realm: string, item: int, qty: int, at: string}): ?array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function rows(array $base, string $kind, string $keyColumn, array $rows, callable $extra, int &$skipped): array
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

            $out[] = $base + [
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
        $realm = Bounds::realm($r['realm'] ?? '');

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
        $fields = [
            $kind, $r['realm'] ?? '', $r['time'] ?? '', $r['itemString'] ?? '',
            $r['quantity'] ?? '', $r['price'] ?? '', $r['source'] ?? '',
        ];

        // Only addon buys carry an exact total; adding it only then keeps every existing TSM key.
        if (isset($r['total'])) {
            $fields[] = $r['total'];
        }

        $base = sha1(implode('|', $fields));

        $occurrence[$base] = ($occurrence[$base] ?? -1) + 1;

        return sha1($base.'#'.$occurrence[$base]);
    }

    /**
     * Addon sales that only know the item's name get its id when exactly one commodity in the
     * catalog has that name. The rest stay without an id and are skipped (a name shared by quality
     * tiers is never guessed).
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, string>>
     */
    private function resolveSaleNames(array $rows, string $game): array
    {
        $names = [];

        foreach ($rows as $r) {
            if (isset($r['itemName'])) {
                $names[mb_strtolower($r['itemName'])] = true;
            }
        }

        if ($names === []) {
            return $rows;
        }

        // The catalog is retail's: a Forever name is never matched against it (stays unresolved).
        $ids = Game::hasMarket($game) ? ItemName::idsByName(array_keys($names), commoditiesOnly: true) : [];

        foreach ($rows as &$r) {
            if (isset($r['itemName'])) {
                $found = $ids[mb_strtolower($r['itemName'])] ?? [];
                $r['itemString'] = count($found) === 1 ? 'i:'.$found[0] : '';
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Latest snapshot only. A file older than the snapshot we hold doesn't overwrite it, and a
     * file with no inventory at all doesn't wipe it.
     *
     * @param  array<string, int>  $inventory
     * @param  array<int, true>  $touched
     * @return array{items: int, applied: bool}
     */
    private function replaceInventory(User $user, string $game, array $inventory, int $takenAt, bool $trustedTime, array &$touched, bool $merge = false): array
    {
        $rows = [];
        $taken = Utc::sql($takenAt);

        foreach ($inventory as $baseId => $qty) {
            $id = ItemName::numericId($baseId);

            if ($id === null || ! Bounds::validItemId($id) || $qty < 1 || $qty > Bounds::MAX_INVENTORY) {
                continue;
            }

            $touched[$id] = true;
            $rows[] = ['user_id' => $user->id, 'game' => $game, 'item_id' => $id, 'qty' => $qty, 'taken_at' => $taken, 'origin' => $merge ? TsmData::ORIGIN_ADDON : TsmData::ORIGIN_TSM];
        }

        $latest = DB::table('inventory')->ownedBy($user)->inGame($game)->max('taken_at');

        // Without a trustworthy file time, "now" must not let a stale file replace a newer snapshot.
        if ($rows === [] || ($latest !== null && ($taken < $latest || ! $trustedTime))) {
            return ['items' => count($rows), 'applied' => false];
        }

        // The addon's scan can be partial (a bank never opened), so it only updates the items it saw;
        // a TSM file is a full count and replaces everything. The addon keeps each store as last
        // seen, so an item it counted before and no longer lists is gone: its row is dropped.
        if ($merge) {
            Bulk::upsert('inventory', $rows, ['user_id', 'game', 'item_id'], ['qty', 'taken_at', 'origin']);
            DB::table('inventory')->ownedBy($user)->inGame($game)->where('origin', TsmData::ORIGIN_ADDON)->where('taken_at', '<', $taken)->delete();
        } else {
            DB::table('inventory')->ownedBy($user)->inGame($game)->delete();
            Bulk::insertOrIgnore('inventory', $rows);
        }

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
