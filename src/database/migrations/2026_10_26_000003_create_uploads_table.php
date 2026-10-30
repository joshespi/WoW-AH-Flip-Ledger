<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per upload attempt. The raw file is never stored; only status, counts and errors.
     */
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('client_version', 32)->nullable();
            $table->string('wow_account', 64)->nullable();
            // When TSM wrote the file (uploader header or File.lastModified), UTC.
            $table->dateTime('file_mtime')->nullable();
            $table->dateTime('received_at');
            $table->unsignedInteger('bytes');
            $table->string('status', 16);
            $table->string('error', 500)->nullable();
            $table->json('counts')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
