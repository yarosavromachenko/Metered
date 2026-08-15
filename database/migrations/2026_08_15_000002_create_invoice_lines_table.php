<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->uuid('invoice_id');
            $table->unsignedSmallInteger('position');

            $table->uuid('organization_id');
            $table->uuid('project_id');

            // Carried from the invoice so that "what has been billed for this
            // meter in this period" is one indexed read across a
            // subscription's invoices — the question every late line asks.
            $table->uuid('subscription_id');

            $table->string('kind', 8);
            $table->string('description', 255);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('covers_start', 6);
            $table->timestampTz('covers_end', 6);

            $table->uuid('price_id');
            $table->uuid('meter_id')->nullable();
            $table->string('meter_code', 64)->nullable();
            $table->decimal('quantity', 38, 6)->nullable();

            // The working, one step per element, as the pricing model wrote it.
            $table->jsonb('calculation');

            $table->primary(['invoice_id', 'position']);
            $table->index(['subscription_id', 'meter_id', 'covers_start']);

            $table->foreign(['invoice_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('invoices')
                ->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_kind_check
                CHECK (kind IN ('fixed', 'usage', 'late'));
            ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_amount_check
                CHECK (amount_minor >= 0);
            ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_covers_check
                CHECK (covers_end > covers_start);
            -- A fixed line has no meter and no quantity; a metered one has both.
            ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_meter_check
                CHECK (
                    (kind = 'fixed') = (meter_id IS NULL)
                    AND (meter_id IS NULL) = (meter_code IS NULL)
                    AND (meter_id IS NULL) = (quantity IS NULL)
                    AND (quantity IS NULL OR quantity >= 0)
                );

            -- Lines are written once, with their draft, and never touched.
            CREATE OR REPLACE FUNCTION invoice_lines_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF (SELECT status FROM invoices WHERE id = NEW.invoice_id) IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'invoice % is no longer a draft; its lines are fixed', NEW.invoice_id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'invoice lines are never changed or removed'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;

            CREATE TRIGGER invoice_lines_write_once
                BEFORE INSERT OR UPDATE OR DELETE ON invoice_lines
                FOR EACH ROW EXECUTE FUNCTION invoice_lines_refuse_rewrite();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS invoice_lines_refuse_rewrite()');
    }
};
