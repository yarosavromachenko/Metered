<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            // UUIDv7 like every other identifier here. A bigint would have
            // been the one auto-incrementing id in the system, and the one
            // that leaks how many people have signed up.
            $table->uuid('id')->primary();

            $table->string('name', 120);
            $table->string('email', 254)->unique();
            $table->string('password');
            $table->timestampTz('last_signed_in_at', 6)->nullable();
            $table->rememberToken();
            $table->timestampsTz(6);
        });

        // No password_reset_tokens table: there is no mail infrastructure in
        // this project, so there is no reset flow to back it (ADR-0017). A
        // forgotten demo password means a new demo account, and the sign-up
        // screen says so.

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
