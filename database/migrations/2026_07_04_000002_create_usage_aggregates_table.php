<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usage_aggregates', function (Blueprint $table): void {
            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('customer_id');
            $table->uuid('meter_id');

            // As on the events: the identifiers a tenant knows, carried so
            // that the screen showing an aggregate — and the invoice line
            // built from it — needs nothing else to be readable. Both are
            // immutable, so neither can drift from the catalog.
            $table->string('meter_code', 64);
            $table->string('customer_ref', 128);

            // The hour the events fell in. Second precision: a bucket start is
            // always on the hour, and a column that could hold microseconds
            // could hold two rows where there must be one.
            $table->timestampTz('bucket_start', 0);

            $table->decimal('quantity', 38, 6);

            // How many events the number above was folded from. Not derivable
            // afterwards — a sum of three events and a sum of thirty look the
            // same — and it is what `usage:reconcile` compares against the raw
            // rows.
            $table->unsignedBigInteger('event_count')->default(0);

            $table->timestampTz('updated_at', 6);

            // What invoicing reads, and what the consumer upserts into.
            $table->primary(['project_id', 'customer_id', 'meter_id', 'bucket_start']);

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE usage_aggregates ADD CONSTRAINT usage_aggregates_quantity_check
             CHECK (quantity >= 0)',
        );

        // A bucket is an hour, and nothing else is a valid start for one.
        DB::statement(
            "ALTER TABLE usage_aggregates ADD CONSTRAINT usage_aggregates_bucket_check
             CHECK (bucket_start = date_trunc('hour', bucket_start))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_aggregates');
    }
};
