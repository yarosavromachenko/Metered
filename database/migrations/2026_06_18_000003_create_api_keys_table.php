<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Both tenant columns, as every tenant-owned table carries them
            // (ADR-0013). Here they are also checked against each other: see
            // the composite foreign key below.
            $table->uuid('organization_id');
            $table->uuid('project_id');

            $table->string('name', 80);

            // What a lookup finds. Unique, so authentication never has to
            // decide between two candidate rows.
            $table->char('prefix', 8)->unique();

            // SHA-256 of the whole token, hex. The secret is not here, and
            // there is no column it could be in.
            $table->char('secret_hash', 64);

            $table->string('environment', 4);
            $table->jsonb('scopes');

            $table->timestampTz('created_at', 6);
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->timestampTz('last_used_at', 6)->nullable();

            // Listing a project's keys, newest first, is the panel's query.
            $table->index(['project_id', 'created_at']);

            // The invariant that matters: a key's project, organization and
            // environment must be one consistent triple. Written as a foreign
            // key rather than checked in a handler, so a raw INSERT from a
            // seeder or a console session cannot produce a test key that
            // authenticates against a live project.
            $table->foreign(['project_id', 'organization_id', 'environment'])
                ->references(['id', 'organization_id', 'environment'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE api_keys ADD CONSTRAINT api_keys_environment_check
             CHECK (environment IN ('live', 'test'))",
        );

        DB::statement(
            "ALTER TABLE api_keys ADD CONSTRAINT api_keys_secret_hash_check
             CHECK (secret_hash ~ '^[0-9a-f]{64}$')",
        );

        // A key with no scopes can authenticate and then do nothing, which is
        // a state worth making impossible rather than merely unlikely.
        DB::statement(
            "ALTER TABLE api_keys ADD CONSTRAINT api_keys_scopes_check
             CHECK (jsonb_typeof(scopes) = 'array' AND jsonb_array_length(scopes) > 0)",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
