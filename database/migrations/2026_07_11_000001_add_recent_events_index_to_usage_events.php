<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // The usage explorer opens on a project's newest events, and the
        // ingestion widget counts the last hour of them. Neither index the
        // table had can answer that: the primary key leads with event_id
        // after the project, the reading index with customer and meter. So
        // each page load sorted every event the project had ever sent — 9M
        // rows and 700ms a query, three times a page, and growing with the
        // table (docs/query-plans.md).
        //
        // With this index each partition hands its rows back newest first,
        // the partitions merge in order, and a page reads ten rows. `id` is
        // left out even though the explorer sorts by it second: it only
        // breaks ties inside one microsecond, which an incremental sort does
        // for free, and it would add sixteen bytes to every entry of the
        // largest index in the system. The price that remains is one more
        // index maintained on every insert, measured in the same document.
        DB::statement(
            'CREATE INDEX usage_events_recent_index
             ON usage_events (project_id, occurred_at)',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX usage_events_recent_index');
    }
};
