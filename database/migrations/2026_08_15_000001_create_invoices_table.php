<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('customer_id');
            $table->uuid('subscription_id');

            $table->char('currency', 3);
            $table->timestampTz('period_start', 6);
            $table->timestampTz('period_end', 6);
            $table->string('status', 16);

            // Assigned at finalization from the organization's counter, never
            // before: a draft that is discarded must not use up a number.
            $table->unsignedInteger('number')->nullable();

            // The sum of the lines, kept on the row so that a list of invoices
            // is one read. The lines never change, so neither does this.
            $table->bigInteger('total_minor');

            $table->timestampTz('built_at', 6);
            $table->timestampTz('finalized_at', 6)->nullable();
            $table->timestampTz('paid_at', 6)->nullable();
            $table->timestampTz('voided_at', 6)->nullable();

            // One subscription and one period make at most one invoice. This
            // is the guarantee; the job's overlap lock only saves the work of
            // building a second one (ADR-0010).
            $table->unique(['subscription_id', 'period_start', 'period_end']);
            $table->unique(['organization_id', 'number']);
            $table->unique(['id', 'project_id']);
            $table->index(['project_id', 'status', 'built_at']);
            $table->index(['project_id', 'customer_id']);

            $table->foreign(['subscription_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('subscriptions')
                ->restrictOnDelete();

            $table->foreign(['customer_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('customers')
                ->restrictOnDelete();

            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
                CHECK (status IN ('draft', 'finalized', 'paid', 'void'));
            ALTER TABLE invoices ADD CONSTRAINT invoices_period_check
                CHECK (period_end > period_start);
            ALTER TABLE invoices ADD CONSTRAINT invoices_total_check
                CHECK (total_minor >= 0);
            ALTER TABLE invoices ADD CONSTRAINT invoices_number_check
                CHECK (number IS NULL OR number >= 1);

            -- A number exactly when finalized; a finalized or paid invoice has
            -- both. A void one may have neither (a discarded draft) or both.
            ALTER TABLE invoices ADD CONSTRAINT invoices_finalization_check
                CHECK (
                    (number IS NULL) = (finalized_at IS NULL)
                    AND (status NOT IN ('finalized', 'paid') OR number IS NOT NULL)
                    AND (status <> 'draft' OR number IS NULL)
                    AND ((status = 'paid') = (paid_at IS NOT NULL))
                    AND ((status = 'void') = (voided_at IS NOT NULL))
                );

            -- What the domain allows, held again for rows written without it:
            -- nothing on an invoice changes except its status moving forward,
            -- and the instant that records it. Paid and void are final.
            CREATE OR REPLACE FUNCTION invoices_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'invoice % cannot be deleted; void it instead', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF OLD.status IN ('paid', 'void')
                    OR (OLD.status = 'finalized' AND NEW.status NOT IN ('finalized', 'paid', 'void'))
                    OR (OLD.number IS NOT NULL AND NEW.number IS DISTINCT FROM OLD.number)
                    OR (OLD.finalized_at IS NOT NULL AND NEW.finalized_at IS DISTINCT FROM OLD.finalized_at)
                    OR (NEW.organization_id, NEW.project_id, NEW.customer_id, NEW.subscription_id, NEW.currency,
                        NEW.period_start, NEW.period_end, NEW.total_minor, NEW.built_at)
                       IS DISTINCT FROM
                       (OLD.organization_id, OLD.project_id, OLD.customer_id, OLD.subscription_id, OLD.currency,
                        OLD.period_start, OLD.period_end, OLD.total_minor, OLD.built_at)
                THEN
                    RAISE EXCEPTION 'invoice % is % and cannot change that way', OLD.id, OLD.status
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER invoices_append_only
                BEFORE UPDATE OR DELETE ON invoices
                FOR EACH ROW EXECUTE FUNCTION invoices_refuse_rewrite();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS invoices_refuse_rewrite()');
    }
};
