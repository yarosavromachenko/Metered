<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A demo tenant is deleted a week after its people stop signing in,
        // money history and all (ADR-0016). That is only acceptable for a
        // tenant that was a demo from the start, so the fact is stored once,
        // when the organization is created, and never changed: the invoicing
        // triggers read it before they let a purge through.
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('demo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('demo');
        });
    }
};
