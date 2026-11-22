<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item names the user's own addon learned, for games with no shared catalog (Forever, Classic).
     * Per user, never shared: the catalog is retail's and a user-supplied name must not claim an id.
     */
    public function up(): void
    {
        Schema::create('user_item_names', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 16);
            $table->unsignedBigInteger('item_id');
            $table->string('name', 120);

            $table->primary(['user_id', 'game', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_item_names');
    }
};
