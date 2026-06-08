<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('consumer', 128);
            $table->uuid('message_id');
            $table->timestampTz('processed_at');

            // The guarantee itself. Not an index for speed — the unique
            // constraint is what makes a second delivery a no-op, and it is
            // enforced by the database rather than by a check-then-act in PHP.
            $table->unique(['consumer', 'message_id'], 'inbox_messages_consumer_message_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
