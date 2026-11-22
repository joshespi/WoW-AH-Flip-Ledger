<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 8)->default('retail');
            // The item the recipe makes; crafts of it can link to the recipe.
            $table->unsignedBigInteger('item_id');
            $table->string('name', 100);
            $table->bigInteger('cost_copper');
            $table->dateTime('bought_at');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'game']);
        });

        Schema::table('trades', function (Blueprint $table) {
            // On a craft's `craftin` row: the recipe it was made with.
            $table->unsignedBigInteger('recipe_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('trades', fn (Blueprint $table) => $table->dropColumn('recipe_id'));
        Schema::dropIfExists('recipes');
    }
};
