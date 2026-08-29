<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Invoices, their lines, credit notes and the ledger refuse to be
        // deleted, by triggers that bind every role (ADR-0008). A demo tenant
        // still has to go when it expires (ADR-0016), so the triggers admit
        // exactly one exception, and both halves of it are checked here rather
        // than trusted to the caller:
        //
        //  - the transaction has declared which organization it is purging,
        //    with set_config(..., true), which lasts until that transaction
        //    ends and not a statement longer;
        //  - that organization was created as a demo, a flag nothing can
        //    change afterwards.
        //
        // A purge of a real tenant is therefore refused by the database, not
        // by a check somebody could forget. TRUNCATE is still refused outright.
        DB::unprepared(<<<'SQL'
            -- OR REPLACE throughout: `migrate:fresh` drops tables, not functions.
            CREATE OR REPLACE FUNCTION demo_purge_admits(organization uuid) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT current_setting('metered.purging_organization', true) = organization::text
                   AND EXISTS (SELECT 1 FROM organizations WHERE id = organization AND demo);
            $$;

            CREATE OR REPLACE FUNCTION organizations_keep_demo_flag() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'organization % was % a demo and stays that way',
                    OLD.id, CASE WHEN OLD.demo THEN 'created as' ELSE 'not created as' END
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;

            CREATE TRIGGER organizations_demo_fixed
                BEFORE UPDATE OF demo ON organizations
                FOR EACH ROW WHEN (NEW.demo IS DISTINCT FROM OLD.demo)
                EXECUTE FUNCTION organizations_keep_demo_flag();

            CREATE OR REPLACE FUNCTION invoices_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF demo_purge_admits(OLD.organization_id) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'invoice % cannot be deleted; void it instead', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF OLD.status IN ('paid', 'void')
                    OR (OLD.status = 'finalized' AND NEW.status NOT IN ('finalized', 'paid', 'void'))
                    OR (OLD.number IS NOT NULL AND NEW.number IS DISTINCT FROM OLD.number)
                    OR (OLD.finalized_at IS NOT NULL AND NEW.finalized_at IS DISTINCT FROM OLD.finalized_at)
                    OR (NEW.organization_id, NEW.project_id, NEW.customer_id, NEW.customer_ref, NEW.customer_name,
                        NEW.subscription_id, NEW.currency, NEW.period_start, NEW.period_end, NEW.total_minor, NEW.built_at)
                       IS DISTINCT FROM
                       (OLD.organization_id, OLD.project_id, OLD.customer_id, OLD.customer_ref, OLD.customer_name,
                        OLD.subscription_id, OLD.currency, OLD.period_start, OLD.period_end, OLD.total_minor, OLD.built_at)
                THEN
                    RAISE EXCEPTION 'invoice % is % and cannot change that way', OLD.id, OLD.status
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

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

                IF TG_OP = 'DELETE' AND demo_purge_admits(OLD.organization_id) THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'invoice lines are never changed or removed'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;

            CREATE OR REPLACE FUNCTION credit_notes_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND demo_purge_admits(OLD.organization_id) THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'credit notes are never changed or removed'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;

            CREATE OR REPLACE FUNCTION ledger_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_LEVEL = 'ROW' AND TG_OP = 'DELETE' AND demo_purge_admits(OLD.organization_id) THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'the ledger is append-only: % on % is refused', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
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
                    OR (NEW.organization_id, NEW.project_id, NEW.customer_id, NEW.customer_ref, NEW.customer_name,
                        NEW.subscription_id, NEW.currency, NEW.period_start, NEW.period_end, NEW.total_minor, NEW.built_at)
                       IS DISTINCT FROM
                       (OLD.organization_id, OLD.project_id, OLD.customer_id, OLD.customer_ref, OLD.customer_name,
                        OLD.subscription_id, OLD.currency, OLD.period_start, OLD.period_end, OLD.total_minor, OLD.built_at)
                THEN
                    RAISE EXCEPTION 'invoice % is % and cannot change that way', OLD.id, OLD.status
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

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

            CREATE OR REPLACE FUNCTION credit_notes_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'credit notes are never changed or removed'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;

            CREATE OR REPLACE FUNCTION ledger_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'the ledger is append-only: % on % is refused', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;

            DROP TRIGGER IF EXISTS organizations_demo_fixed ON organizations;
            DROP FUNCTION IF EXISTS organizations_keep_demo_flag();
            DROP FUNCTION IF EXISTS demo_purge_admits(uuid);
            SQL);
    }
};
