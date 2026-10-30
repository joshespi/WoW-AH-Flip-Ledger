<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blizzard price snapshots create items before any name is known, so the name may be null
     * (display "Item #id" until a TSM upload or the Blizzard item API fills it).
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('name', 120)->nullable()->change();
            $table->string('quality', 16)->nullable()->after('name_source');
            $table->boolean('is_commodity')->default(false)->after('quality');
            // Last Blizzard item lookup, successful or not, so a 404 isn't retried every run.
            $table->dateTime('fetched_at')->nullable()->after('is_commodity');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['quality', 'is_commodity', 'fetched_at']);
        });
    }
};
