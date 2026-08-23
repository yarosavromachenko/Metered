<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');

            $table->string('url', 2048);
            $table->string('description', 255)->default('');
            $table->jsonb('event_types');

            // Encrypted with the application key. The secret signs every
            // delivery, so unlike an API key it has to be recoverable; it is
            // never stored, logged or shown in the clear after creation.
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestampTz('previous_secret_expires_at', 6)->nullable();

            $table->boolean('enabled')->default(true);

            $table->string('breaker_state', 16)->default('closed');
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestampTz('breaker_changed_at', 6)->nullable();

            $table->timestampTz('created_at', 6);

            $table->unique(['id', 'project_id']);
            $table->index('project_id');

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_breaker_check
                CHECK (breaker_state IN ('closed', 'open', 'half_open')
                       AND (breaker_state = 'closed' OR breaker_changed_at IS NOT NULL));
            ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_previous_secret_check
                CHECK ((previous_secret IS NULL) = (previous_secret_expires_at IS NULL));
            ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_event_types_check
                CHECK (jsonb_typeof(event_types) = 'array' AND jsonb_array_length(event_types) > 0);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
