<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_id');
            $table->string('type', 128);
            $table->jsonb('payload');
            $table->jsonb('headers');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('published_at', 6)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at', 6)->nullable();
            $table->text('last_error')->nullable();
        });

        // The relay only ever asks for unpublished rows, and published rows are
        // the overwhelming majority. A partial index keeps the poll's cost
        // proportional to the backlog rather than to the table.
        DB::statement(
            'CREATE INDEX outbox_messages_unpublished_idx
             ON outbox_messages (occurred_at)
             WHERE published_at IS NULL',
        );

        DB::statement(
            'CREATE INDEX outbox_messages_aggregate_idx
             ON outbox_messages (aggregate_type, aggregate_id, occurred_at)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
