<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('plan_id');

            $table->integer('number');
            $table->char('currency', 3);
            $table->string('interval', 8);

            $table->timestampTz('created_at', 6);
            $table->timestampTz('published_at', 6)->nullable();

            // Numbering is per plan, and the handler's "latest plus one" is
            // only a guess: two drafts started at once meet here.
            $table->unique(['plan_id', 'number']);
            $table->unique(['id', 'project_id']);

            $table->foreign(['plan_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('plans')
                ->cascadeOnDelete();

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_number_check CHECK (number >= 1)');
        DB::statement(
            "ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_interval_check
             CHECK (interval IN ('month', 'year'))",
        );

        // A published version is immutable (assumptions, 21). The domain type
        // refuses the change first; this is what holds when something writes
        // the table without it. Publishing itself — published_at going from
        // null to a value — is the one update allowed.
        //
        // Deletion is not refused here. A version a subscription uses cannot be
        // deleted because subscription_phases restricts it; one nobody uses may
        // go, and must, when its project is deleted and cascades.
        DB::unprepared(<<<'SQL'
            -- OR REPLACE: `migrate:fresh` drops tables, not functions.
            CREATE OR REPLACE FUNCTION plan_versions_refuse_change_after_publication() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'plan version % is published and cannot change', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER plan_versions_locked_after_publication
                BEFORE UPDATE ON plan_versions
                FOR EACH ROW EXECUTE FUNCTION plan_versions_refuse_change_after_publication();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS plan_versions_refuse_change_after_publication()');
    }
};
