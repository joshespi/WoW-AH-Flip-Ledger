<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\User;
use App\Support\Game;
use App\Support\TsmSpan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Undo for a manual or uploader upload. Files are cumulative and inserts skip existing keys, so
 * only the newest successful upload of a game can be undone: nothing newer can have skipped rows
 * this one owns. Rows from before `upload_id` existed are never touched.
 */
class UploadService
{
    /** The upload that may be undone for this user and game, or null. */
    public function undoable(User $user, string $game): ?Upload
    {
        return Upload::ownedBy($user)->succeeded()->inGame($game)->newest()->first();
    }

    /** @return list<int> ids of the uploads that may be undone (the newest successful one per game) */
    public function undoableIds(User $user): array
    {
        return array_values(array_filter(array_map(fn (string $g) => $this->undoable($user, $g)?->id, Game::GAMES)));
    }

    /**
     * @param  bool  $clearInventory  also clear the game's inventory snapshot (it keeps no history)
     * @return array{rows: int, inventory: bool}
     */
    public function undo(User $user, int $id, bool $clearInventory): array
    {
        $user->assertWritable();

        $upload = Upload::ownedBy($user)->findOrFail($id);

        // Held across the checks too: an ingest landing in between would make this one no longer the newest.
        // Ingest takes well under a second, so a held lock is refused rather than waited on.
        $result = IngestService::lock($user)->get(fn () => $this->undoNewest($user, $upload, $clearInventory));

        if ($result === false) {
            throw new InvalidArgumentException(__('An upload for your account is still being processed. Try again in a minute.'));
        }

        return $result;
    }

    /** @return array{rows: int, inventory: bool} */
    private function undoNewest(User $user, Upload $upload, bool $clearInventory): array
    {
        if ($this->undoable($user, $upload->game)?->id !== $upload->id) {
            throw new InvalidArgumentException(__('Only the newest upload for a game can be undone.'));
        }

        $accepted = DB::table('tsm_buy_candidates')->ownedBy($user)->where('upload_id', $upload->id)
            ->where('status', ReconcileService::ACCEPTED)->exists();

        if ($accepted) {
            throw new InvalidArgumentException(__('Some of its buys are already in your trades. Delete those trades first.'));
        }

        return DB::transaction(function () use ($user, $upload, $clearInventory) {
            $rows = 0;

            foreach (array_keys(IngestService::LOG_TABLES) as $table) {
                $rows += DB::table($table)->ownedBy($user)->where('upload_id', $upload->id)->delete();
            }

            if ($clearInventory) {
                DB::table('inventory')->ownedBy($user)->inGame($upload->game)->delete();
            }

            // The span must match the TSM rows still stored.
            TsmSpan::rebuild($user, $upload->game);

            $upload->delete();

            return ['rows' => $rows, 'inventory' => $clearInventory];
        });
    }
}
