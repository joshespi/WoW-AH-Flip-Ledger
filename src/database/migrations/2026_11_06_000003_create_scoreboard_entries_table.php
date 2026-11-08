<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Computed hourly (scoreboard:build) for opted-in users. Money is copper; pnl can be negative.
        Schema::create('scoreboard_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 8);
            $table->string('period', 3);
            $table->bigInteger('pnl_copper');
            $table->bigInteger('sales_copper');
            $table->bigInteger('units');
            $table->unsignedInteger('flips');
            $table->dateTime('computed_at');

            $table->unique(['user_id', 'game', 'period']);
            $table->index(['game', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoreboard_entries');
    }
};
