<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Opt-in: nothing is ever sent until the user turns alerts on and picks rules.
            $table->boolean('alerts_enabled')->default(false);
            $table->boolean('alert_buy_target')->default(true);
            $table->boolean('alert_sell_target')->default(true);
            $table->boolean('alert_profit')->default(false);
        });

        // The last evaluated state of each (item, rule), so an alert fires on the change to "on"
        // and not on every refresh while it stays on. last_sent_at drives the cooldown.
        Schema::create('alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            // buy_target | sell_target | profit
            $table->string('kind', 16);
            $table->boolean('is_on');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['user_id', 'item_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['alerts_enabled', 'alert_buy_target', 'alert_sell_target', 'alert_profit']);
        });
    }
};
