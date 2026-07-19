<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a row elsewhere say "this meter, and it is in my project" in one
 * foreign key. A price names a meter and a subscription names a customer; the
 * composite key is what stops either from naming one that belongs to another
 * project, however the row was written.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE meters ADD CONSTRAINT meters_id_project_unique UNIQUE (id, project_id)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_id_project_unique UNIQUE (id, project_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP CONSTRAINT customers_id_project_unique');
        DB::statement('ALTER TABLE meters DROP CONSTRAINT meters_id_project_unique');
    }
};
