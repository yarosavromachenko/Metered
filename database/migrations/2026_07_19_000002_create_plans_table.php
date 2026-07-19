<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');

            $table->string('code', 64);
            $table->string('name', 120);

            $table->timestampTz('created_at', 6);

            $table->unique(['project_id', 'code']);
            $table->unique(['id', 'project_id']);

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        // The shape PlanCode enforces, for rows that never passed through it.
        DB::statement(
            "ALTER TABLE plans ADD CONSTRAINT plans_code_check
             CHECK (code ~ '^[a-z0-9]+([_-][a-z0-9]+)*$' AND length(code) >= 2)",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
