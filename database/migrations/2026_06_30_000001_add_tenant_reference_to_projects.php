<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Every tenant-owned table carries both ids, and the pair has to be
        // one a foreign key can check. The projects table already publishes
        // (id, organization_id, environment) for keys, which cannot serve a
        // table that has no environment of its own — a meter belongs to a
        // project, and the project's environment is not the meter's business.
        DB::statement(
            'ALTER TABLE projects ADD CONSTRAINT projects_id_organization_unique
             UNIQUE (id, organization_id)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_id_organization_unique');
    }
};
