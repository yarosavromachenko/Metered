<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('plan_version_id');
            $table->uuid('meter_id')->nullable();

            // Position within the version, so the panel and the invoice list
            // prices in the order they were added.
            $table->smallInteger('position');

            $table->string('model', 16);
            $table->char('currency', 3);
            $table->bigInteger('flat_amount')->nullable();
            // Scale 8, matching UnitPrice::SCALE.
            $table->decimal('unit_price', 20, 8)->nullable();
            $table->jsonb('tiers')->nullable();

            // One price per meter within a version (assumptions, 22). Fixed
            // fees have no meter, and nulls are distinct, so they may repeat.
            $table->unique(['plan_version_id', 'meter_id']);
            $table->unique(['plan_version_id', 'position']);

            $table->foreign(['plan_version_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('plan_versions')
                ->cascadeOnDelete();

            $table->foreign(['meter_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('meters')
                ->restrictOnDelete();
        });

        // Each model carries exactly the columns it reads, and a usage model
        // carries a meter. A row that breaks this would price differently
        // from the object it was saved from.
        DB::statement(<<<'SQL'
            ALTER TABLE prices ADD CONSTRAINT prices_model_shape_check CHECK (
                (model = 'flat_fee' AND meter_id IS NULL AND flat_amount >= 0
                    AND unit_price IS NULL AND tiers IS NULL)
                OR (model = 'per_unit' AND meter_id IS NOT NULL AND unit_price >= 0
                    AND flat_amount IS NULL AND tiers IS NULL)
                OR (model IN ('graduated', 'volume') AND meter_id IS NOT NULL
                    AND jsonb_typeof(tiers) = 'array' AND jsonb_array_length(tiers) >= 1
                    AND flat_amount IS NULL AND unit_price IS NULL)
            )
            SQL);

        // Prices of a published version are as fixed as the version itself.
        // A cascade from deleting the version gets through: by the time it
        // reaches the prices, the version row is already gone.
        DB::unprepared(<<<'SQL'
            -- OR REPLACE: `migrate:fresh` drops tables, not functions.
            CREATE OR REPLACE FUNCTION prices_refuse_change_on_published_version() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                version_id uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.plan_version_id ELSE NEW.plan_version_id END;
            BEGIN
                IF EXISTS (SELECT 1 FROM plan_versions WHERE id = version_id AND published_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'plan version % is published; its prices cannot change', version_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER prices_locked_with_their_version
                BEFORE INSERT OR UPDATE OR DELETE ON prices
                FOR EACH ROW EXECUTE FUNCTION prices_refuse_change_on_published_version();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
        DB::unprepared('DROP FUNCTION IF EXISTS prices_refuse_change_on_published_version()');
    }
};
