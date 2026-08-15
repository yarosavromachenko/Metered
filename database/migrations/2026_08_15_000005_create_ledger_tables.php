<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('customer_id');
            $table->uuid('invoice_id');

            $table->string('posting', 24);
            $table->char('currency', 3);
            $table->timestampTz('occurred_at', 6);

            // Each movement happens to an invoice once: finalized once, paid
            // once, credited once. A retried job that books twice fails here.
            $table->unique(['invoice_id', 'posting']);

            $table->foreign(['invoice_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('invoices')
                ->restrictOnDelete();
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('transaction_id');

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('customer_id');

            $table->string('account', 24);
            $table->string('direction', 6);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('occurred_at', 6);

            // A balance is an aggregate over one customer's entries on one
            // account; there is no balance column to drift (ADR-0008).
            $table->index(['project_id', 'customer_id', 'account', 'occurred_at']);
            $table->index('transaction_id');

            $table->foreign('transaction_id')
                ->references('id')
                ->on('ledger_transactions')
                ->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_posting_check
                CHECK (posting IN ('invoice.finalized', 'payment.received', 'credit_note.issued'));
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_check
                CHECK (account IN ('accounts_receivable', 'revenue', 'cash'));
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction_check
                CHECK (direction IN ('debit', 'credit'));
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_check
                CHECK (amount_minor > 0);

            -- History is appended to, never rewritten. Enforced by triggers
            -- rather than REVOKE: the application's role owns these tables,
            -- and an owner — or a superuser — is not bound by revoked
            -- privileges. A trigger binds everyone.
            CREATE OR REPLACE FUNCTION ledger_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'the ledger is append-only: % on % is refused', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;

            CREATE TRIGGER ledger_transactions_append_only
                BEFORE UPDATE OR DELETE ON ledger_transactions
                FOR EACH ROW EXECUTE FUNCTION ledger_refuse_rewrite();
            CREATE TRIGGER ledger_transactions_no_truncate
                BEFORE TRUNCATE ON ledger_transactions
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_refuse_rewrite();
            CREATE TRIGGER ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_refuse_rewrite();
            CREATE TRIGGER ledger_entries_no_truncate
                BEFORE TRUNCATE ON ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_refuse_rewrite();

            -- Debits equal credits within a transaction, checked when the
            -- database transaction commits: entries are inserted one by one,
            -- and only the finished set has to balance.
            CREATE OR REPLACE FUNCTION ledger_entries_check_balance() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                imbalance bigint;
                currencies integer;
            BEGIN
                SELECT sum(CASE direction WHEN 'debit' THEN amount_minor ELSE -amount_minor END),
                       count(DISTINCT currency)
                  INTO imbalance, currencies
                  FROM ledger_entries
                 WHERE transaction_id = NEW.transaction_id;

                IF imbalance <> 0 OR currencies <> 1 THEN
                    RAISE EXCEPTION 'ledger transaction % does not balance', NEW.transaction_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER ledger_entries_balanced
                AFTER INSERT ON ledger_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ledger_entries_check_balance();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_refuse_rewrite()');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_check_balance()');
    }
};
