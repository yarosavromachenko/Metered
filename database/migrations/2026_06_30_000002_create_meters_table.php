<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meters', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');

            // The name events arrive under. Lowercased before it is stored,
            // so the unique index below is the whole of the rule: one code,
            // one meter, per project.
            $table->string('code', 64);
            $table->string('name', 120);
            $table->string('aggregation', 8);

            $table->timestampTz('created_at', 6);

            $table->unique(['project_id', 'code']);

            // Ingestion resolves a code to a meter for every batch it writes,
            // so this lookup is on the hot path of the consumer.
            $table->index(['project_id', 'created_at']);

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE meters ADD CONSTRAINT meters_aggregation_check
             CHECK (aggregation IN ('sum', 'count', 'max'))",
        );

        // The same shape MeterCode enforces. Written twice on purpose: a
        // seeder, a fixture or a console session can insert a row without
        // passing through the value object, and a code that does not match
        // what clients may send is a meter nothing can ever reach.
        DB::statement(
            "ALTER TABLE meters ADD CONSTRAINT meters_code_check
             CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND length(code) >= 2)",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('meters');
    }
};
