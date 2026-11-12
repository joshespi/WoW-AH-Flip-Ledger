<?php

namespace App\Support;

use App\Models\User;
use App\Services\IngestService;
use App\Services\ReconcileService;
use App\Services\TsmData;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "TSM wins where it has data": the stretch a user's TSM uploads have covered, per game and WoW
 * account (`tsm_spans`). TSM's data is per account, so its span only covers addon rows from the
 * same account. Addon rows inside it are not stored; widening it drops the addon rows it now
 * covers. Ingest and undo go through here only.
 *
 * The account '' is unknown (manual uploads, rows from before accounts were kept) and matches
 * any account both ways, which is how every span behaved before.
 */
final class TsmSpan
{
    /**
     * The account a file belongs to: the uploader's label ("acct-1a2b3c-forever-addon") without
     * its game and "-addon" tags, so a TSM file and an addon file of one account match.
     */
    public static function account(?string $label): string
    {
        $games = implode('|', array_diff(Game::GAMES, [Game::RETAIL]));

        return $label === null ? '' : (string) preg_replace("/(?:-(?:{$games}))?(?:-addon)?$/D", '', $label);
    }

    /** @return array{0: string, 1: string}|null [from, to] database times of exactly this account's span, or null */
    public static function get(User $user, string $game, string $account = ''): ?array
    {
        $row = DB::table('tsm_spans')->ownedBy($user)->inGame($game)->where('account', $account)->first(['from', 'to']);

        return $row === null ? null : [$row->from, $row->to];
    }

    /**
     * Every span that covers $account's addon rows.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function covering(User $user, string $game, string $account): array
    {
        return self::accounts(DB::table('tsm_spans')->ownedBy($user)->inGame($game), $account)
            ->get(['from', 'to'])->map(fn ($r) => [$r->from, $r->to])->all();
    }

    /** @param  list<array{0: string, 1: string}>  $spans */
    public static function covers(array $spans, string $time): bool
    {
        foreach ($spans as [$from, $to]) {
            if ($time >= $from && $time <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records a TSM upload's span and drops the addon rows that now fall inside it.
     *
     * @return int addon rows removed
     */
    public static function widen(User $user, string $game, string $account, string $from, string $to): int
    {
        $old = self::get($user, $game, $account);
        [$from, $to] = $old === null ? [$from, $to] : [min($old[0], $from), max($old[1], $to)];

        if ($old === [$from, $to]) {
            return 0;
        }

        DB::table('tsm_spans')->upsert(
            [['user_id' => $user->id, 'game' => $game, 'account' => $account, 'from' => $from, 'to' => $to]],
            ['user_id', 'game', 'account'], ['from', 'to'],
        );

        // Addon rows inside the old span were never kept, so only the newly covered ends need clearing.
        $slices = $old === null ? [[$from, $to]] : array_filter([[$from, $old[0]], [$old[1], $to]], fn ($s) => $s[0] < $s[1]);
        $addonIn = fn (string $table) => self::accounts(DB::table($table)->ownedBy($user)->inGame($game), $account)
            ->where('origin', TsmData::ORIGIN_ADDON)
            ->where(function ($q) use ($slices, $table) {
                foreach ($slices as $slice) {
                    $q->orWhereBetween(IngestService::LOG_TABLES[$table], $slice);
                }
            });

        self::carryIgnored($user, $game, $account, $addonIn('tsm_buy_candidates'));

        $removed = 0;

        foreach (array_keys(IngestService::LOG_TABLES) as $table) {
            $removed += $addonIn($table)->delete();
        }

        return $removed;
    }

    /**
     * An addon buy the user ignored is about to be replaced by TSM's record of the same buy: the
     * nearest pending TSM candidate (same item and qty, within ReconcileService::WINDOW) takes
     * the ignore, so it does not come back. Accepted buys need nothing: their trade hides the twin.
     */
    private static function carryIgnored(User $user, string $game, string $account, Builder $addonBuys): void
    {
        foreach ($addonBuys->where('status', ReconcileService::IGNORED)->get(['item_id', 'qty', 'at']) as $c) {
            $at = Utc::ts($c->at);

            $twin = self::accounts(DB::table('tsm_buy_candidates')->ownedBy($user)->inGame($game), $account)
                ->where('origin', TsmData::ORIGIN_TSM)->where('status', ReconcileService::PENDING)
                ->where('item_id', $c->item_id)->where('qty', $c->qty)
                ->whereBetween('at', [Utc::sql($at - ReconcileService::WINDOW), Utc::sql($at + ReconcileService::WINDOW)])
                ->get(['id', 'at'])
                ->sortBy(fn ($t) => abs(Utc::ts($t->at) - $at))
                ->first();

            if ($twin !== null) {
                DB::table('tsm_buy_candidates')->where('id', $twin->id)->update(['status' => ReconcileService::IGNORED]);
            }
        }
    }

    /**
     * Rebuilds every account's span from the TSM rows still stored (after an undo). None would let
     * addon rows double-count older TSM data, and a span left wide would hide addon rows the undone
     * upload's widening deleted. Each ends at its newest remaining TSM row, not a file time; addon
     * rows past it are kept on their next upload.
     */
    public static function rebuild(User $user, string $game): void
    {
        $spans = [];

        foreach (IngestService::LOG_TABLES as $table => $column) {
            $ranges = DB::table($table)->ownedBy($user)->inGame($game)->where('origin', TsmData::ORIGIN_TSM)
                ->groupBy('account')->selectRaw("account, min({$column}) as lo, max({$column}) as hi")->get();

            foreach ($ranges as $r) {
                $a = (string) $r->account;
                $spans[$a] = isset($spans[$a]) ? [min($spans[$a][0], $r->lo), max($spans[$a][1], $r->hi)] : [$r->lo, $r->hi];
            }
        }

        DB::table('tsm_spans')->ownedBy($user)->inGame($game)->delete();
        DB::table('tsm_spans')->insert(array_map(
            fn (string $a, array $s) => ['user_id' => $user->id, 'game' => $game, 'account' => $a, 'from' => $s[0], 'to' => $s[1]],
            array_keys($spans), $spans,
        ));
    }

    /** Rows of $account and of the unknown account; every row when $account is unknown. */
    private static function accounts(Builder $q, string $account): Builder
    {
        return $account === '' ? $q : $q->whereIn('account', [$account, '']);
    }
}
