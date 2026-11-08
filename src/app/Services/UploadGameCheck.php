<?php

namespace App\Services;

use App\Models\User;
use App\Support\Bounds;
use App\Support\Game;
use Illuminate\Support\Facades\DB;

/**
 * Stops a file being filed under the wrong game (a Forever TradeSkillMaster.lua uploaded as
 * Retail mixes Forever buys into the retail ledger).
 *
 * An addon file says which client wrote it (TsmData::$game): a mismatch is always refused. A TSM
 * file says nothing, so for manual uploads its realms are compared with the user's stored rows;
 * the person can confirm and upload anyway. The uploader picks the game from the install folder,
 * so it skips the realm guess.
 */
class UploadGameCheck
{
    /** Realms compared (a TSM file lists a handful; this only bounds the query). */
    private const MAX_REALMS = 100;

    /** Realms named in a message. */
    private const SHOWN = 4;

    /**
     * Why the file must not be filed under $game, and whether the person may confirm it anyway;
     * null when it may be filed there.
     *
     * @return array{0: string, 1: bool}|null
     */
    public function problem(User $user, string $game, TsmData $data, bool $checkRealms): ?array
    {
        if ($data->game !== null) {
            return $data->game === $game ? null : [__('This file was saved by the :file client but was uploaded as :game. Upload it as :file.', [
                'file' => Game::label($data->game),
                'game' => Game::label($game),
            ]), false];
        }

        $problem = $checkRealms ? $this->realmProblem($user, $game, $data) : null;

        return $problem === null ? null : [$problem, true];
    }

    private function realmProblem(User $user, string $game, TsmData $data): ?string
    {
        $realms = array_slice($data->realms(), 0, self::MAX_REALMS);
        $keys = array_values(array_unique(array_map(Bounds::realmKey(...), $realms)));

        if ($keys === []) {
            return null;
        }

        $known = $this->knownRealms($user, $keys);

        if (isset($known[$game])) {
            return null;
        }

        $names = $this->names($realms);
        $others = array_keys($known);
        $enabled = $user->enabledGames();

        // Every realm in the file is one the user only has in one other game.
        if (count($others) === 1 && count($known[$others[0]]) === count($keys)) {
            $other = Game::label($others[0]);

            // The form only offers enabled games, so a switched-off one needs Settings first.
            return __('This file\'s realms (:realms) are in your :other data, not :game. Upload it as :other, or tick "Upload anyway" if it really is from :game.', [
                'realms' => $names,
                'other' => $other,
                'game' => Game::label($game),
            ]).(in_array($others[0], $enabled, true) ? '' : ' '.__('Turn :other on in Settings first.', ['other' => $other]));
        }

        if ($this->hasRows($user, $game)) {
            return __('None of this file\'s realms (:realms) are in your :game data. Check which game folder it came from, or tick "Upload anyway" if it really is from :game.', [
                'realms' => $names,
                'game' => Game::label($game),
            ]).($user->playsSeveral() ? '' : ' '.__('If it is from another game, turn that game on in Settings first.'));
        }

        return null;
    }

    /**
     * The user's stored realms among $keys (Bounds::realmKey, so TSM and addon rows match), by game.
     *
     * @param  list<string>  $keys
     * @return array<string, array<string, true>>
     */
    private function knownRealms(User $user, array $keys): array
    {
        $known = [];

        foreach (array_keys(IngestService::LOG_TABLES) as $table) {
            $rows = DB::table($table)->ownedBy($user)->whereIn(DB::raw(Bounds::realmKeySql('realm')), $keys)
                ->distinct()->get(['game', 'realm']);

            foreach ($rows as $r) {
                $known[$r->game][Bounds::realmKey($r->realm)] = true;
            }
        }

        return $known;
    }

    private function hasRows(User $user, string $game): bool
    {
        foreach (array_keys(IngestService::LOG_TABLES) as $table) {
            if (DB::table($table)->ownedBy($user)->inGame($game)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $realms */
    private function names(array $realms): string
    {
        $shown = array_map(static fn (string $r): string => Bounds::text($r, 40) ?? '?', array_slice($realms, 0, self::SHOWN));
        $more = count($realms) - count($shown);

        return implode(', ', $shown).($more > 0 ? ' '.__('and :n more', ['n' => $more]) : '');
    }
}
