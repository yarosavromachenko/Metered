<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Lets the exclusion constraint below compare a uuid for equality in
        // the same GiST index as a range for overlap.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('subscription_phases', function (Blueprint $table): void {
            $table->uuid('subscription_id');

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('plan_version_id');

            $table->timestampTz('starts_at', 6);
            $table->timestampTz('ends_at', 6)->nullable();

            $table->primary(['subscription_id', 'starts_at']);

            $table->foreign(['subscription_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('subscriptions')
                ->cascadeOnDelete();

            // Same project, and a version that stays: a version with a
            // subscription on it can never be deleted.
            $table->foreign(['plan_version_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('plan_versions')
                ->restrictOnDelete();

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE subscription_phases ADD CONSTRAINT subscription_phases_order_check
             CHECK (ends_at IS NULL OR ends_at >= starts_at)',
        );

        // Two phases of one subscription never overlap, so no instant is priced
        // on two versions. Half-open ranges, like the periods they cover.
        DB::statement(
            "ALTER TABLE subscription_phases ADD CONSTRAINT subscription_phases_no_overlap
             EXCLUDE USING gist (subscription_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)",
        );

        // Only a published version can be subscribed to (assumptions, 21).
        DB::unprepared(<<<'SQL'
            -- OR REPLACE: `migrate:fresh` drops tables, not functions.
            CREATE OR REPLACE FUNCTION subscription_phases_require_published_version() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM plan_versions WHERE id = NEW.plan_version_id AND published_at IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'plan version % is not published', NEW.plan_version_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER subscription_phases_on_published_versions
                BEFORE INSERT OR UPDATE OF plan_version_id ON subscription_phases
                FOR EACH ROW EXECUTE FUNCTION subscription_phases_require_published_version();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_phases');
        DB::unprepared('DROP FUNCTION IF EXISTS subscription_phases_require_published_version()');
    }
};
