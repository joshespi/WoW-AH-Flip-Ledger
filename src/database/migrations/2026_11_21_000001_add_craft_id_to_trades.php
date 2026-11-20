<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            // Groups one craft's rows: its `craftin` row's id.
            $table->unsignedBigInteger('craft_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('trades', fn (Blueprint $table) => $table->dropColumn('craft_id'));
    }
};
