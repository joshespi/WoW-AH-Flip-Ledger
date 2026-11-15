<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deposits forfeited on expired or cancelled auctions, as the addon matched them to the post.
     * TSM never has these, so the table is not span-filtered and stays out of IngestService::LOG_TABLES.
     */
    public function up(): void
    {
        Schema::create('ah_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 8)->default('retail');
            $table->string('origin', 8)->default('addon');
            $table->unsignedBigInteger('upload_id')->nullable()->index();
            $table->string('account', 64)->default('');
            $table->string('realm', 64);
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('lost_copper');
            $table->dateTime('at');
            $table->char('dedupe_key', 40);

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'game', 'item_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ah_deposits');
    }
};
