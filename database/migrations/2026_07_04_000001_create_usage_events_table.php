<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Raw DDL rather than a Blueprint: this is the one table in the system
        // that is partitioned, and partitioning is not something the schema
        // builder expresses.
        DB::statement(<<<'SQL'
            CREATE TABLE usage_events (
                id               uuid           NOT NULL,
                organization_id  uuid           NOT NULL,
                project_id       uuid           NOT NULL,
                event_id         varchar(128)   NOT NULL,
                customer_id      uuid           NOT NULL,
                meter_id         uuid           NOT NULL,

                -- The code and the reference the client actually sent, beside
                -- the ids they resolved to.
                --
                -- Denormalised deliberately, and safe to denormalise: both are
                -- immutable by contract — a meter's code cannot change and a
                -- customer's reference cannot change — so there is no update
                -- that could make these disagree with the catalog. What they
                -- buy is an event row that is a faithful record of what
                -- arrived, which is what support questions are asked about,
                -- and a usage explorer that needs no join across a module
                -- boundary to be readable.
                meter_code       varchar(64)    NOT NULL,
                customer_ref     varchar(128)   NOT NULL,
                quantity         numeric(20, 6) NOT NULL,
                occurred_at      timestamptz(6) NOT NULL,
                received_at      timestamptz(6) NOT NULL,
                properties       jsonb          NOT NULL DEFAULT '{}'::jsonb,

                -- The natural key, and the reason partitioning shapes this
                -- table: a unique index on a partitioned table must contain
                -- the partition key, so deduplication and the range the row
                -- lives in are one and the same decision (ADR-0002).
                --
                -- It is also what the consumer's ON CONFLICT targets, which
                -- makes "insert what is new, return what was inserted" a
                -- single statement (ADR-0004).
                PRIMARY KEY (project_id, event_id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        // A UUIDv7 handle for one row — for a link in the panel, a line in a
        // trace, an export. Deliberately not indexed: nothing looks a row up
        // by it, and a second index on the largest table in the system would
        // be paid for on every insert forever.
        DB::statement("COMMENT ON COLUMN usage_events.id IS 'Row handle; not indexed, nothing resolves by it'");

        DB::statement('ALTER TABLE usage_events ADD CONSTRAINT usage_events_quantity_check CHECK (quantity >= 0)');

        // Everything an invoice is built from reads this: usage for one
        // customer on one meter over a period.
        DB::statement(
            'CREATE INDEX usage_events_reading_index
             ON usage_events (project_id, customer_id, meter_id, occurred_at)',
        );

        // The first events usually arrive before the scheduler has ever run,
        // so the table is created with a fortnight of partitions around
        // today: a week back, because that is how far the acceptance window
        // reaches, and a week forward. Without them the very first event
        // would land in the default partition and the scheduled command could
        // no longer carve today out from under it.
        $day = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $day = $day->sub(new DateInterval('P7D'));

        for ($i = 0; $i <= 14; $i++) {
            $next = $day->add(new DateInterval('P1D'));

            DB::statement(sprintf(
                "CREATE TABLE usage_events_p%s PARTITION OF usage_events FOR VALUES FROM ('%s') TO ('%s')",
                $day->format('Ymd'),
                $day->format('Y-m-d H:i:sP'),
                $next->format('Y-m-d H:i:sP'),
            ));

            $day = $next;
        }

        // Where rows land when the scheduled command has not created the
        // partition they belong to. They stop being pruned and start being
        // slow, which is the point: an ingestion that keeps working badly is
        // better than one that stops, and the panel counts partitions so the
        // failure is visible (ADR-0002).
        DB::statement('CREATE TABLE usage_events_default PARTITION OF usage_events DEFAULT');
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
