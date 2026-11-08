<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // /stats counts sales per day across all users; every other tsm_sales index leads with user_id.
        Schema::table('tsm_sales', function (Blueprint $table) {
            $table->index('sold_at');
        });
    }

    public function down(): void
    {
        Schema::table('tsm_sales', function (Blueprint $table) {
            $table->dropIndex(['sold_at']);
        });
    }
};
