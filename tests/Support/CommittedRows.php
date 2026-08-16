<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Infrastructure\Persistence\RowReader;

/**
 * Removes what a concurrency test committed for one organization.
 *
 * Those tests must commit — a race inside one transaction proves nothing —
 * and some of what they commit is append-only by design: invoices, the
 * ledger, the audit chain. Leaving it would break every later test that
 * counts rows, so it is removed with the triggers switched off, for this
 * transaction only. The test role is the database's superuser; nothing in the
 * application may do this, and nothing in it does.
 */
final class CommittedRows
{
    public static function purgeOrganization(Uuid $organizationId, string $auditActor): void
    {
        DB::transaction(static function () use ($organizationId, $auditActor): void {
            DB::statement('SET LOCAL session_replication_role = replica');

            $members = DB::table('organization_members')->where('organization_id', $organizationId->value)->pluck('user_id')->all();

            $tables = DB::select(
                "SELECT c.table_name FROM information_schema.columns c
                   JOIN information_schema.tables t ON t.table_name = c.table_name AND t.table_schema = c.table_schema
                  WHERE c.table_schema = 'public' AND c.column_name = 'organization_id' AND t.table_type = 'BASE TABLE'",
            );

            foreach ($tables as $table) {
                DB::table(RowReader::string(is_object($table) ? get_object_vars($table)['table_name'] ?? null : null, 'table_name'))
                    ->where('organization_id', $organizationId->value)
                    ->delete();
            }

            DB::table('outbox_messages')->whereRaw("payload->>'organization_id' = ?", [$organizationId->value])->delete();
            DB::table('audit_log')->where('actor', $auditActor)->delete();
            DB::table('users')->whereIn('id', $members)->delete();
            DB::table('organizations')->where('id', $organizationId->value)->delete();
        });
    }
}
