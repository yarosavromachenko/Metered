<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('endpoint_id');

            // The integration event's id, which is also the payload's `id`:
            // what a receiver deduplicates on.
            $table->uuid('event_id');
            $table->string('event_type', 64);

            // Text, not jsonb: jsonb reorders keys and drops whitespace, and
            // every attempt must send — and sign — the same bytes.
            $table->text('body');

            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at', 6)->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->timestampTz('last_attempt_at', 6)->nullable();
            $table->timestampTz('created_at', 6);

            // One delivery per event per endpoint, whatever redelivers the
            // event: the fan-out inserts and ignores what already exists.
            $table->unique(['endpoint_id', 'event_id']);
            $table->unique(['id', 'project_id']);
            $table->index(['project_id', 'created_at']);
            $table->index(['endpoint_id', 'created_at']);

            $table->foreign(['endpoint_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('webhook_endpoints')
                ->cascadeOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check
                CHECK (status IN ('pending', 'succeeded', 'failed', 'dead'));
            ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_schedule_check
                CHECK ((status = 'pending') = (next_attempt_at IS NOT NULL) AND attempts <= 10);

            -- What the dispatcher asks every few seconds: the pending
            -- deliveries whose time has come, and nothing else.
            CREATE INDEX webhook_deliveries_due_index
                ON webhook_deliveries (next_attempt_at) WHERE status = 'pending';
            SQL);

        Schema::create('webhook_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('delivery_id');
            $table->uuid('organization_id');
            $table->uuid('project_id');

            $table->unsignedSmallInteger('number');
            $table->timestampTz('attempted_at', 6);
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 255)->nullable();

            // The first kilobyte of what the receiver answered: enough to see
            // why it refused, never enough to hold a whole payload.
            $table->text('response_excerpt');

            $table->index(['delivery_id', 'id']);

            $table->foreign(['delivery_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('webhook_deliveries')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_attempts');
        Schema::dropIfExists('webhook_deliveries');
    }
};
