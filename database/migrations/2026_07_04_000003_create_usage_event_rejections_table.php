<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usage_event_rejections', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');

            // Null when the message could not be read as an event at all, and
            // therefore had no id to read either.
            $table->string('event_id', 128)->nullable();

            $table->string('reason', 32);
            $table->string('detail', 500);

            // The event exactly as it arrived. It is the tenant's own data,
            // inside their own project, and "what did we actually send?" has
            // no other answer once the client has been told 202.
            $table->jsonb('payload');

            $table->timestampTz('rejected_at', 6);

            // The screen: this project's rejections, newest first, filtered by
            // reason or searched by the id the client knows.
            $table->index(['project_id', 'rejected_at']);
            $table->index(['project_id', 'reason', 'rejected_at']);
            $table->index(['project_id', 'event_id']);

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE usage_event_rejections ADD CONSTRAINT usage_event_rejections_reason_check
             CHECK (reason IN ('malformed', 'unknown_meter', 'unknown_customer', 'too_old', 'in_the_future'))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_event_rejections');
    }
};
