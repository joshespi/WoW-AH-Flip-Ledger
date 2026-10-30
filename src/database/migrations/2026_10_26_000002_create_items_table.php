<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared item catalog. Phase 4 adds the Blizzard-sourced columns (icon, quality, fetched_at).
     * Names from user uploads only fill gaps and never override a Blizzard name.
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name', 120);
            $table->string('name_source', 16)->default('tsm');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
