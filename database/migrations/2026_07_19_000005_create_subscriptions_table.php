<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('customer_id');

            $table->timestampTz('anchor_at', 6);
            $table->char('currency', 3);
            $table->string('interval', 8);
            $table->string('status', 24);
            $table->timestampTz('ends_at', 6)->nullable();

            $table->unique(['id', 'project_id']);
            $table->index(['project_id', 'customer_id']);
            // The period close asks for every running subscription.
            $table->index(['project_id', 'status']);

            $table->foreign(['customer_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('customers')
                ->restrictOnDelete();

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check
             CHECK (status IN ('active', 'pending_cancellation', 'canceled'))",
        );
        DB::statement(
            "ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_interval_check
             CHECK (interval IN ('month', 'year'))",
        );
        // Only an active subscription runs without an end.
        DB::statement(
            "ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_end_check
             CHECK ((status = 'active') = (ends_at IS NULL) AND (ends_at IS NULL OR ends_at >= anchor_at))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
