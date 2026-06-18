<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('slug', 63);
            $table->string('environment', 4);
            $table->char('currency', 3);

            $table->timestampTz('created_at', 6);

            // A slug is unique inside its organization, not globally: two
            // tenants may both call a project "production".
            $table->unique(['organization_id', 'slug']);

            // Not redundant with the primary key. Rows elsewhere reference a
            // project together with the organization and environment they
            // believe it has, and a composite foreign key needs a unique
            // index to point at — see the api_keys table.
            $table->unique(['id', 'organization_id', 'environment']);
        });

        DB::statement(
            "ALTER TABLE projects ADD CONSTRAINT projects_environment_check
             CHECK (environment IN ('live', 'test'))",
        );

        DB::statement(
            "ALTER TABLE projects ADD CONSTRAINT projects_currency_check
             CHECK (currency ~ '^[A-Z]{3}$')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
