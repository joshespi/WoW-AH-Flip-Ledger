<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Sell-price target is avg cost x (1 + margin) / (1 - AH cut).
            $table->decimal('target_margin_pct', 5, 2)->default(15)->after('region');
            // TSM only writes its file on /reload or logout; older than this and the header warns.
            $table->unsignedSmallInteger('stale_minutes')->default(30)->after('target_margin_pct');
            // Share of region sales assumed to be yours when estimating days to sell.
            $table->decimal('market_share_pct', 5, 2)->default(5)->after('stale_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['target_margin_pct', 'stale_minutes', 'market_share_pct']);
        });
    }
};
