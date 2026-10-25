<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most ten webhook endpoints per project, disabled ones included
 * (assumptions, 52). Endpoints a project already has above the limit stay.
 *
 * AFTER INSERT, because saving an endpoint is an upsert: a BEFORE INSERT
 * trigger fires for the update of an existing row too, and would refuse a
 * breaker change in a project at its limit. The advisory lock serialises two
 * registrations racing for the last place; the count after it sees the
 * winner's row because each statement takes a new snapshot under READ
 * COMMITTED, the isolation level the application runs at.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- OR REPLACE: `migrate:fresh` drops tables, not functions.
            CREATE OR REPLACE FUNCTION webhook_endpoints_within_limit() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('webhook_endpoints:' || NEW.project_id::text, 0));

                IF (SELECT count(*) FROM webhook_endpoints WHERE project_id = NEW.project_id) > 10 THEN
                    RAISE EXCEPTION 'webhook_endpoints_per_project_limit: project % has 10 webhook endpoints', NEW.project_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER webhook_endpoints_per_project_limit
                AFTER INSERT ON webhook_endpoints
                FOR EACH ROW EXECUTE FUNCTION webhook_endpoints_within_limit();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS webhook_endpoints_per_project_limit ON webhook_endpoints');
        DB::unprepared('DROP FUNCTION IF EXISTS webhook_endpoints_within_limit()');
    }
};
