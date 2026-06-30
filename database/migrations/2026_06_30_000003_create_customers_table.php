<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');

            // The tenant's own id for this customer, stored exactly as they
            // sent it. Case included: it is a key into their system.
            $table->string('reference', 128);
            $table->string('name', 120);

            $table->timestampTz('created_at', 6);

            $table->unique(['project_id', 'reference']);
            $table->index(['project_id', 'created_at']);

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        // Visible ASCII, as CustomerReference requires: the reference travels
        // in URLs, log lines and problem documents.
        DB::statement(
            "ALTER TABLE customers ADD CONSTRAINT customers_reference_check
             CHECK (reference ~ '^[\\x21-\\x7E]+$')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
