<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);

            // Unique across the platform, because an organization slug is how
            // a tenant is addressed in the panel and in demo URLs.
            $table->string('slug', 63)->unique();

            $table->timestampTz('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
