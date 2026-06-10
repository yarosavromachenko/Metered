<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The scope a key is unique within. Today it is the API key's
            // project; two tenants sending the same key must never collide.
            $table->string('scope', 64);
            $table->string('idempotency_key', 255);

            // Hash of method, path and body. A repeat of the same key with a
            // different request is a client bug, and this is how it is caught.
            $table->char('request_fingerprint', 64);

            $table->string('status', 16);

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->text('response_body')->nullable();

            $table->timestampTz('created_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');

            // The guarantee. Claiming is INSERT ... ON CONFLICT DO NOTHING
            // against this constraint, so the winner is decided by the database
            // rather than by a read followed by a write.
            $table->unique(['scope', 'idempotency_key'], 'idempotency_keys_scope_key_unique');
        });

        DB::statement(
            "ALTER TABLE idempotency_keys
             ADD CONSTRAINT idempotency_keys_status_check
             CHECK (status IN ('in_progress', 'completed'))",
        );

        DB::statement(
            'CREATE INDEX idempotency_keys_expiry_idx ON idempotency_keys (expires_at)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
