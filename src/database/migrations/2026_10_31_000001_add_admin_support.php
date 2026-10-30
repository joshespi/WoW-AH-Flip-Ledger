<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Granted only by `php artisan admin:grant`; not mass-assignable.
            $table->boolean('is_admin')->default(false)->after('is_demo');
        });

        // Who did what to whom. Ids are kept (no foreign keys) so the trail outlives a deleted
        // account; the email is copied for the same reason.
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('admin_email');
            $table->string('action', 32);
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('target_email')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
