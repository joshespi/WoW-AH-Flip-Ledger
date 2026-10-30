<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The trades page lists a user's buys newest first without filtering by item.
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->index(['user_id', 'traded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'traded_at']);
        });
    }
};
