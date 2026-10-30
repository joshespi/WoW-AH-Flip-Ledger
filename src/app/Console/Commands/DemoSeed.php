<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\IngestService;
use App\Services\TradesImportService;
use App\Services\TsmParser;
use App\Services\WatchlistService;
use App\Support\Bounds;
use App\Support\Bulk;
use App\Support\ItemName;
use App\Support\Utc;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeed extends Command
{
    public const EMAIL = 'demo@demo.invalid';

    protected $signature = 'demo:seed';

    protected $description = 'Create (or reset) the read-only demo account from the anonymized sample data';

    public function handle(IngestService $ingest, TsmParser $parser, TradesImportService $trades, WatchlistService $watchlist): int
    {
        $lua = database_path('demo/TradeSkillMaster.lua');
        $csv = database_path('demo/trades.csv');

        if (! is_file($lua) || ! is_file($csv)) {
            $this->error('Sample data is missing from database/demo.');

            return self::FAILURE;
        }

        // The `.invalid` TLD cannot receive mail and the password is random and thrown away, so
        // nobody can sign in as the demo user except through the demo button.
        $user = DB::transaction(function () use ($lua, $csv, $ingest, $parser, $trades) {
            User::where('email', self::EMAIL)->get()->each->delete();

            $user = new User(['name' => 'Demo trader', 'email' => self::EMAIL, 'password' => Str::random(64), 'timezone' => 'UTC']);
            $user->forceFill(['is_demo' => true, 'email_verified_at' => now()])->save();

            $contents = (string) file_get_contents($lua);

            // Uploads cannot add catalog rows (the catalog is shared), but this file is ours:
            // name its items so the demo reads the same before the price fetch has run.
            $now = Utc::sql();
            $rows = [];

            foreach ($parser->parse($contents)->names as $base => $name) {
                $id = ItemName::numericId($base);
                $name = ItemName::sanitize($name);

                if ($id !== null && $name !== null && Bounds::validItemId($id)) {
                    $rows[] = ['id' => $id, 'name' => $name, 'name_source' => 'tsm', 'is_commodity' => true, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            Bulk::insertOrIgnore('items', $rows);

            $ingest->ingest($user, $contents, 'manual');
            $trades->import($user, (string) file_get_contents($csv));

            return $user;
        });

        foreach (DB::table('items')->whereIn('name', ['Arcane Wyrmfish', 'Void-Tempered Leather', 'Practically Pork'])->pluck('id') as $id) {
            $watchlist->add($user, (int) $id, 'Sample');
        }

        $this->info('Demo account ready ('.self::EMAIL.'). Market numbers come from the shared price data.');

        return self::SUCCESS;
    }
}
