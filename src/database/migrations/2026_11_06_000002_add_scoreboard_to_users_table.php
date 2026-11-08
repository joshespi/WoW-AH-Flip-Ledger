<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('scoreboard_opt_in')->default(false);
            $table->string('scoreboard_nickname', 24)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['scoreboard_nickname']);
            $table->dropColumn(['scoreboard_opt_in', 'scoreboard_nickname']);
        });
    }
};
