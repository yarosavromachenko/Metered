<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A counter row per organization and kind of document, advanced inside
        // the transaction that uses the number. Not a PostgreSQL SEQUENCE: a
        // sequence does not roll back, and a rolled-back finalization would
        // leave a gap in the numbering (ADR-0010).
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->uuid('organization_id');
            $table->string('kind', 16);
            $table->unsignedInteger('last_number');

            $table->primary(['organization_id', 'kind']);

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_kind_check
             CHECK (kind IN ('invoice', 'credit_note'))",
        );
        DB::statement(
            'ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_number_check
             CHECK (last_number >= 1)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
