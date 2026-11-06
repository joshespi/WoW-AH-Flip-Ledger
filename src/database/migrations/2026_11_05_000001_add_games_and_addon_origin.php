<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The ingest log tables, with their time column. */
    private const LOG_TABLES = ['tsm_sales' => 'sold_at', 'tsm_expired' => 'at', 'tsm_cancelled' => 'at', 'tsm_buy_candidates' => 'at'];

    /**
     * Games (Retail, Forever, Classic; existing rows are retail) and the companion addon as a
     * second source. Log rows carry where they came from (`origin`) and which upload inserted
     * them (`upload_id`, for undo). TSM wins where it has data: `tsm_spans` is the stretch TSM
     * has covered per game and WoW account (TSM's data is per account), and addon rows inside it are
     * not kept.
     */
    public function up(): void
    {
        foreach (self::LOG_TABLES as $name => $time) {
            Schema::table($name, function (Blueprint $table) use ($time) {
                $table->string('game', 8)->default('retail');
                $table->string('origin', 8)->default('tsm');
                $table->unsignedBigInteger('upload_id')->nullable()->index();
                // The uploader's WoW account label without game and addon tags; '' = unknown (see TsmSpan).
                $table->string('account', 64)->default('');
                // Clearing addon rows a TSM upload now covers.
                $table->index(['user_id', 'game', 'origin', $time]);
            });
        }

        foreach (['trades', 'uploads'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('game', 8)->default('retail');
            });
        }

        // One snapshot per item per game; the new unique key is added first (it also serves the user_id foreign key).
        Schema::table('inventory', function (Blueprint $table) {
            $table->string('game', 8)->default('retail');
            $table->string('origin', 8)->default('tsm');
            $table->unique(['user_id', 'game', 'item_id']);
        });

        Schema::table('inventory', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'item_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('enabled_games', 64)->default('retail');
            $table->string('game_view', 8)->default('retail');
        });

        Schema::create('tsm_spans', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 8);
            $table->string('account', 64)->default('');
            $table->dateTime('from');
            $table->dateTime('to');
            $table->primary(['user_id', 'game', 'account']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsm_spans');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['enabled_games', 'game_view']);
        });

        // The old key allows one row per item: only retail's snapshot survives.
        DB::table('inventory')->where('game', '!=', 'retail')->delete();

        Schema::table('inventory', function (Blueprint $table) {
            $table->unique(['user_id', 'item_id']);
        });

        Schema::table('inventory', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'game', 'item_id']);
            $table->dropColumn(['game', 'origin']);
        });

        foreach (['trades', 'uploads'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('game');
            });
        }

        foreach (self::LOG_TABLES as $name => $time) {
            Schema::table($name, function (Blueprint $table) use ($time) {
                $table->dropIndex(['user_id', 'game', 'origin', $time]);
                $table->dropIndex(['upload_id']);
                $table->dropColumn(['game', 'origin', 'upload_id', 'account']);
            });
        }
    }
};
