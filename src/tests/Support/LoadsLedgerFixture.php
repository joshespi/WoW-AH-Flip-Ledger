<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\IngestService;
use App\Services\TradesImportService;
use App\Services\TsmParser;
use App\Support\ItemName;
use Illuminate\Support\Facades\DB;

/**
 * Loads the anonymized fixture (TSM file + trades, pinned to the 12:13 save the plan's known
 * answers come from) into a user's account, so screens and services are tested on real shapes.
 */
trait LoadsLedgerFixture
{
    use Fixtures, SeedsMarketData;

    /** Item ids in the fixture trades. */
    protected const WYRMFISH = 238371;

    protected const LEATHER = 238511;

    protected const SWARMER = 238365;

    protected const PORK = 242639;

    protected const ROOT = 251285;

    protected const BLOOMLINE = 262792;

    protected const GLIMMERLINE = 262797;

    protected const SHIMMERSIREN = 238378;

    protected function loadLedgerFixture(User $user): void
    {
        $lua = $this->fixture('tsm/TradeSkillMaster.lua');

        // The catalog is the price fetch's; uploads only name rows that exist.
        foreach (app(TsmParser::class)->parse($lua)->names as $base => $name) {
            $id = (int) ItemName::numericId($base);

            if ($id > 0 && ! DB::table('items')->where('id', $id)->exists()) {
                $this->seedItem($id, $name);
            }
        }

        app(IngestService::class)->ingest($user, $lua, 'manual');
        app(TradesImportService::class)->import($user, $this->fixture('tsm/trades.csv'));
    }

    /**
     * A user with the fixture loaded, and the fixture prices too when `$prices` is set.
     *
     * @param  array<string, mixed>  $attributes  overrides for the user
     */
    protected function fixtureUser(bool $prices = false, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->loadLedgerFixture($user);

        if ($prices) {
            $this->seedFixturePrices();
        }

        return $user;
    }

    /**
     * Prices for the fixture items covering every state: profit, under water, below and above
     * typical, collecting data (Swarmer 5 days, Glimmerline 3), and no price (Petrified Root).
     */
    protected function seedFixturePrices(): void
    {
        $this->seedStats([
            self::WYRMFISH => ['floor' => 400_000, 'days' => 20, 'recent' => 410_000, 'typical' => 420_000, 'hist60' => 430_000, 'sold' => 1200],
            self::SWARMER => ['floor' => 3_000, 'days' => 5, 'recent' => 3_100, 'typical' => 3_200, 'hist60' => 3_300, 'sold' => 9000],
            self::PORK => ['floor' => 5_200, 'days' => 20, 'recent' => 5_300, 'typical' => 7_000, 'hist60' => 6_800, 'sold' => 40_000],
            self::SHIMMERSIREN => ['floor' => 330_000, 'days' => 25, 'recent' => 320_000, 'typical' => 250_000, 'hist60' => 260_000, 'sold' => 900],
            self::BLOOMLINE => ['floor' => 31_000, 'days' => 14, 'recent' => 30_500, 'typical' => 30_000, 'hist60' => 29_800, 'sold' => 8000],
            self::GLIMMERLINE => ['floor' => 29_000, 'days' => 3],
        ]);
    }
}
