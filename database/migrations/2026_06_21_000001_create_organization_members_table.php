<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20);
            $table->timestampTz('created_at', 6);

            // A person has one role per organization. Two rows would be two
            // answers to "what may they do?", and the code would have to pick
            // one. The surrogate key above exists only so that the row is
            // addressable on its own, the way every other row here is.
            $table->unique(['organization_id', 'user_id']);

            // "Which organizations can this person switch between?" is the
            // query the panel runs on every request.
            $table->index('user_id');
        });

        DB::statement(
            "ALTER TABLE organization_members ADD CONSTRAINT organization_members_role_check
             CHECK (role IN ('owner', 'admin', 'billing_operator', 'viewer'))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
