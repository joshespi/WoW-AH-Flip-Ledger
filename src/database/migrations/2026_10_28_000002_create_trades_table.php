<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user's own buys: the cost side of the ledger. (Sales come from TSM's log, not here.)
     * cost_copper is the lot total, not per unit. `side` already allows 'adjust' for write-offs;
     * qty and cost are signed so an adjustment can be negative. How an adjustment affects P/L
     * is still open (PLAN.md), so the ledger only reads side = 'buy' for now.
     */
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->dateTime('traded_at');
            $table->bigInteger('qty');
            $table->bigInteger('cost_copper');
            $table->string('side', 8)->default('buy');
            $table->string('note', 255)->nullable();
            // manual | paste | csv | tsm
            $table->string('source', 8)->default('manual');
            // TSM suggestion this trade was accepted from (Phase 6 reconcile).
            $table->char('tsm_ref', 40)->nullable();
            // CSV re-imports are idempotent: hash of the row plus its occurrence among identical
            // rows in the file. Null for hand-entered trades, which may legitimately repeat.
            $table->char('import_key', 40)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'import_key']);
            $table->index(['user_id', 'item_id', 'traded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
