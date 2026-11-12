<?php

namespace App\Services;

use App\Mail\PriceAlertsMail;
use App\Models\User;
use App\Support\Bulk;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Price alerts for one user, from the latest prices. Three opt-in rules:
 *  - buy_target:  a watchlisted item's floor is at or under its buy target;
 *  - sell_target: a watchlisted item's floor is at or over its sell target;
 *  - profit:      a held position's floor now clears its break-even after the AH cut.
 *
 * Each (item, rule) has a stored on/off state. An email goes out on the change to "on", never
 * while it stays on, and not again within the cooldown of the last email for that pair even if
 * it flaps. A user gets at most one email per run listing everything that fired. States are
 * saved only after the email was handed to the mailer, so a failure retries on the next run.
 * Floors are exact, so the rules need no price history.
 */
class AlertService
{
    public function __construct(private WatchlistService $watchlist, private PositionsService $positions) {}

    /**
     * Evaluates, mails and records. Returns the number of alerts sent.
     */
    public function run(User $user, ?int $now = null): int
    {
        $now ??= time();
        $current = $this->current($user);
        $states = DB::table('alert_states')->ownedBy($user)->get()->keyBy(fn ($s) => $s->kind.':'.$s->item_id);

        $cooldown = (int) config('ledger.alerts.cooldown_hours') * Utc::HOUR;
        $cap = (int) config('ledger.alerts.max_items_per_email');
        $send = [];
        $rows = [];

        foreach ($current as $key => $c) {
            $prev = $states[$key] ?? null;
            $lastSent = $prev?->last_sent_at;
            $becameOn = $c['on'] && ($prev === null || ! $prev->is_on);
            $cooling = $lastSent !== null && $now - Utc::ts($lastSent) < $cooldown;

            if ($becameOn && ! $cooling) {
                if (count($send) >= $cap) {
                    continue; // left as it was, so the next run picks it up
                }

                $send[$key] = $c;
                $lastSent = Utc::sql($now);
            }

            // Most pairs don't change between runs; only write the ones that did.
            if ($prev === null || (bool) $prev->is_on !== $c['on'] || $prev->last_sent_at !== $lastSent) {
                $rows[] = ['user_id' => $user->id, 'item_id' => $c['item_id'], 'kind' => $c['kind'], 'is_on' => $c['on'],
                    'last_sent_at' => $lastSent, 'updated_at' => Utc::sql($now)];
            }
        }

        if ($send !== []) {
            Mail::to($user->email)->send(new PriceAlertsMail($user, array_values($send)));
        }

        DB::transaction(function () use ($user, $rows, $current, $states) {
            // Pairs that no longer exist (item unwatched, target cleared, rule switched off) start fresh if they return.
            $gone = [];

            foreach ($states->keys()->diff(array_keys($current)) as $key) {
                [$kind, $item] = explode(':', $key, 2);
                $gone[$kind][] = (int) $item;
            }

            foreach ($gone as $kind => $items) {
                DB::table('alert_states')->ownedBy($user)->where('kind', $kind)->whereIn('item_id', $items)->delete();
            }

            Bulk::upsert('alert_states', $rows, ['user_id', 'item_id', 'kind'], ['is_on', 'last_sent_at', 'updated_at']);
        });

        return count($send);
    }

    /**
     * The current on/off state of every (item, rule) this user has switched on.
     *
     * Callers that already hold the watchlist rows or portfolio can pass them to skip a rebuild.
     *
     * @param  ?list<array<string, mixed>>  $watch
     * @return array<string, array{kind: string, item_id: int, name: string, on: bool, floor: ?int, target: ?int}>
     */
    public function current(User $user, ?array $watch = null, ?Portfolio $portfolio = null): array
    {
        $out = [];

        if ($user->alert_buy_target || $user->alert_sell_target) {
            foreach ($watch ?? $this->watchlist->rows($user) as $r) {
                if ($user->alert_buy_target && $r['buy'] !== null && $r['floor'] !== null) {
                    $out['buy_target:'.$r['item_id']] = ['kind' => 'buy_target', 'item_id' => $r['item_id'], 'name' => $r['name'], 'on' => $r['at_buy'], 'floor' => $r['floor'], 'target' => $r['buy']];
                }

                if ($user->alert_sell_target && $r['sell'] !== null && $r['floor'] !== null) {
                    $out['sell_target:'.$r['item_id']] = ['kind' => 'sell_target', 'item_id' => $r['item_id'], 'name' => $r['name'], 'on' => $r['at_sell'], 'floor' => $r['floor'], 'target' => $r['sell']];
                }
            }
        }

        if ($user->alert_profit) {
            foreach (($portfolio ?? $this->positions->forUser($user))->open() as $p) {
                if ($p->status !== null && $p->floor > 0) {
                    $out['profit:'.$p->itemId] = ['kind' => 'profit', 'item_id' => $p->itemId, 'name' => $p->name, 'on' => $p->status === 'profit', 'floor' => $p->floor, 'target' => (int) ceil($p->breakEven)];
                }
            }
        }

        return $out;
    }
}
