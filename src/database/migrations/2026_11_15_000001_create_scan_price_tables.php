<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prices for games with no Blizzard feed (Forever, Classic), recorded by the user's addon
     * whenever they look at the auction house. Same shape as price_snapshots / item_stats, plus
     * `user_id` and `game`. Per user, never shared: one user's scans cannot move another's floor.
     * Prices are copper per unit.
     */
    public function up(): void
    {
        // One row per item per hour (taken_at is cut to the hour; the newest upload of an hour wins,
        // as the addon keeps the newest scan of it).
        Schema::create('scan_prices', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('region', 2);
            $table->string('game', 16);
            $table->dateTime('taken_at');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('floor');
            $table->unsignedBigInteger('quantity');
            // Listings seen; 0 when the scan only saw the lowest price and total (a browse).
            $table->unsignedInteger('num_auctions')->default(0);

            $table->primary(['user_id', 'region', 'game', 'taken_at', 'item_id']);
            $table->index(['user_id', 'region', 'game', 'item_id', 'taken_at']);
            $table->index('taken_at');
        });

        // Latest numbers per item, recomputed after an upload that sent scans.
        Schema::create('scan_stats', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('region', 2);
            $table->string('game', 16);
            $table->unsignedBigInteger('item_id');
            $table->dateTime('taken_at');
            $table->unsignedBigInteger('floor');
            $table->unsignedBigInteger('quantity');
            $table->unsignedInteger('num_auctions')->default(0);
            $table->unsignedBigInteger('recent')->nullable();
            $table->unsignedBigInteger('typical')->nullable();
            $table->unsignedBigInteger('hist60')->nullable();
            // Always null: scans are too sparse to tell sales from cancels, so none is guessed.
            $table->unsignedInteger('sold_per_day_est')->nullable();
            $table->unsignedSmallInteger('days_of_data')->default(0);
            $table->dateTime('updated_at');

            $table->primary(['user_id', 'region', 'game', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_stats');
        Schema::dropIfExists('scan_prices');
    }
};
