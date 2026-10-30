<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->string('group_name', 64)->nullable();
            // Per unit, copper. Highlight when floor <= buy or >= sell.
            $table->unsignedBigInteger('target_buy_copper')->nullable();
            $table->unsignedBigInteger('target_sell_copper')->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'item_id']);
            $table->index(['user_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_items');
    }
};
