<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user rows parsed from TradeSkillMaster.lua. The file is cumulative, so every upload
     * re-sends history: dedupe_key (hash of the row plus its occurrence index among identical
     * rows) makes inserts idempotent while still keeping genuinely identical sales.
     * No buyer or seller names are stored.
     */
    public function up(): void
    {
        Schema::create('tsm_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('realm', 64);
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('qty');
            // Per unit, after the 5% AH cut (TSM's csvSales.price).
            $table->unsignedBigInteger('unit_price_copper');
            $table->dateTime('sold_at');
            $table->string('source', 16);
            $table->char('dedupe_key', 40);

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'item_id', 'sold_at']);
        });

        foreach (['tsm_expired', 'tsm_cancelled'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('realm', 64);
                $table->unsignedBigInteger('item_id');
                $table->unsignedInteger('qty');
                $table->dateTime('at');
                $table->char('dedupe_key', 40);

                $table->unique(['user_id', 'dedupe_key']);
                $table->index(['user_id', 'item_id', 'at']);
            });
        }

        // csvBuys is unreliable for commodities: suggestions for reconciliation, never ledger input.
        Schema::create('tsm_buy_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('tsm_ref', 40);
            $table->string('realm', 64);
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('cost_copper');
            $table->dateTime('at');
            $table->string('status', 16)->default('pending');

            $table->unique(['user_id', 'tsm_ref']);
            $table->index(['user_id', 'status', 'at']);
        });

        // Latest snapshot only; replaced on each upload unless the file is older than what we hold.
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('qty');
            $table->dateTime('taken_at');

            $table->unique(['user_id', 'item_id']);
        });
    }

    public function down(): void
    {
        foreach (['inventory', 'tsm_buy_candidates', 'tsm_cancelled', 'tsm_expired', 'tsm_sales'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
