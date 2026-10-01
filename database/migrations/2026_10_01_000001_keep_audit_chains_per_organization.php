<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One audit chain per organization (ADR-0020).
 *
 * Rows already written keep no organization: they are the platform chain,
 * valid exactly as they are, so nothing is rewritten — nothing could be, with
 * UPDATE revoked. No foreign key: a purged demo organization's chain outlives
 * the organization, which is the point of an audit trail.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_log ADD COLUMN organization_id uuid NULL');

        // Finding a chain's newest entry, which every append does.
        DB::statement('CREATE INDEX audit_log_organization_id_sequence_index ON audit_log (organization_id, sequence)');

        // A chain cannot fork: two entries in one chain may not claim the same
        // predecessor. The advisory lock serialises writers; this is what holds
        // if it ever does not. NULLS NOT DISTINCT makes the platform chain one
        // chain rather than a set of unrelated rows.
        DB::statement('ALTER TABLE audit_log ADD CONSTRAINT audit_log_organization_id_prev_hash_unique UNIQUE NULLS NOT DISTINCT (organization_id, prev_hash)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_log DROP CONSTRAINT audit_log_organization_id_prev_hash_unique');
        DB::statement('DROP INDEX audit_log_organization_id_sequence_index');
        DB::statement('ALTER TABLE audit_log DROP COLUMN organization_id');
    }
};
