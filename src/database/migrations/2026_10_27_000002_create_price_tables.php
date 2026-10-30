<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared market data, keyed by region. Prices are copper per unit.
     */
    public function up(): void
    {
        // One row per commodity per Blizzard snapshot (hourly). Pruned after snapshot_days.
        Schema::create('price_snapshots', function (Blueprint $table) {
            $table->char('region', 2);
            $table->dateTime('taken_at');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('floor');
            $table->unsignedBigInteger('quantity');
            $table->unsignedInteger('num_auctions');

            $table->primary(['region', 'taken_at', 'item_id']);
            $table->index(['region', 'item_id', 'taken_at']);
        });

        // One row per commodity per UTC day, rolled up from the snapshots. Kept long term.
        Schema::create('price_daily', function (Blueprint $table) {
            $table->char('region', 2);
            $table->date('day');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('floor_min');
            $table->unsignedBigInteger('floor_median');
            $table->unsignedBigInteger('qty_avg');
            $table->unsignedInteger('auctions_avg');
            $table->unsignedSmallInteger('samples');

            $table->primary(['region', 'day', 'item_id']);
            $table->index(['region', 'item_id', 'day']);
        });

        // Latest numbers per commodity, recomputed after each fetch. Derived fields are estimates
        // built from our own snapshots (Blizzard publishes only current listings).
        Schema::create('item_stats', function (Blueprint $table) {
            $table->char('region', 2);
            $table->unsignedBigInteger('item_id');
            $table->dateTime('taken_at');
            $table->unsignedBigInteger('floor');
            $table->unsignedBigInteger('quantity');
            $table->unsignedInteger('num_auctions');
            // Median floor over the last 24 h.
            $table->unsignedBigInteger('recent')->nullable();
            // Median of daily median floors over 30 d, and the mean over 60 d.
            $table->unsignedBigInteger('typical')->nullable();
            $table->unsignedBigInteger('hist60')->nullable();
            // Units/day from hourly supply drops over sold_window_days. Cancels and expiries count too.
            $table->unsignedInteger('sold_per_day_est')->nullable();
            // Distinct days with data in the last 60 d.
            $table->unsignedSmallInteger('days_of_data')->default(0);
            $table->dateTime('updated_at');

            $table->primary(['region', 'item_id']);
        });

        Schema::create('fetch_runs', function (Blueprint $table) {
            $table->id();
            $table->char('region', 2);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            // ok | unchanged | failed
            $table->string('status', 16);
            $table->unsignedInteger('items')->default(0);
            $table->unsignedBigInteger('auctions')->default(0);
            // Blizzard's own snapshot time (Last-Modified), UTC.
            $table->dateTime('last_modified')->nullable();
            $table->string('error', 500)->nullable();

            $table->index(['region', 'started_at']);
        });
    }

    public function down(): void
    {
        foreach (['fetch_runs', 'item_stats', 'price_daily', 'price_snapshots'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
