<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->uuid('customer_id');

            // A credit note reverses the whole of one voided invoice, so an
            // invoice has at most one.
            $table->uuid('invoice_id')->unique();

            $table->unsignedInteger('number');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 500);
            $table->timestampTz('issued_at', 6);

            $table->unique(['organization_id', 'number']);

            $table->foreign(['invoice_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('invoices')
                ->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_amount_check CHECK (amount_minor > 0);
            ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_number_check CHECK (number >= 1);

            CREATE OR REPLACE FUNCTION credit_notes_refuse_rewrite() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'credit notes are never changed or removed'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;

            CREATE TRIGGER credit_notes_append_only
                BEFORE UPDATE OR DELETE ON credit_notes
                FOR EACH ROW EXECUTE FUNCTION credit_notes_refuse_rewrite();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        DB::unprepared('DROP FUNCTION IF EXISTS credit_notes_refuse_rewrite()');
    }
};
