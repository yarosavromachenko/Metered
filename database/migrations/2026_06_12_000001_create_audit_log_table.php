<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Position in the chain. A sequence rather than a timestamp,
            // because two entries can share a millisecond and a chain needs a
            // total order.
            $table->bigIncrements('sequence')->unique();

            $table->string('actor', 255);
            $table->string('action', 128);
            $table->string('subject_type', 64);
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('payload');
            // Microseconds, explicitly: the column's default precision is
            // seconds, and a truncated instant changes the entry's hash.
            $table->timestampTz('occurred_at', 6);

            $table->char('prev_hash', 64);
            $table->char('hash', 64)->unique();

            $table->index(['subject_type', 'subject_id', 'sequence']);
            $table->index(['actor', 'sequence']);
        });

        // The point of the exercise: even the application cannot rewrite
        // history. Altering a row breaks its hash, altering the hash breaks
        // the next row, and deleting one breaks the link — but only if nothing
        // is allowed to do it quietly in the first place.
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM PUBLIC');
        $role = DB::getConfig('username');

        if (is_string($role) && $role !== '') {
            DB::statement(sprintf('REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM %s', $role));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
