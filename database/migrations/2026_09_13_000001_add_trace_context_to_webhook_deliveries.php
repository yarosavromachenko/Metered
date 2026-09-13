<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The W3C trace context of the event a delivery was created for
        // (ADR-0012). A delivery is attempted by a scheduled pass, not by the
        // job that created it, so the context has to be stored to survive the
        // gap. Null for deliveries created with tracing off.
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->jsonb('trace_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('trace_context');
        });
    }
};
