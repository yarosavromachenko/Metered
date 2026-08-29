<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\TenantDataPurger;

/**
 * A purged demo tenant's invoices, credit notes, ledger and numbering.
 *
 * Every one of these tables refuses deletion by trigger (ADR-0008). The
 * triggers admit one exception: a transaction that has declared the
 * organization it is purging, for an organization created as a demo. The
 * declaration is made here, lasts until the transaction ends, and is worth
 * nothing for a real tenant — the database checks the demo flag itself.
 */
final readonly class DatabaseInvoicingPurger implements TenantDataPurger
{
    public function __construct(private DatabaseManager $db) {}

    public function purgeOrganization(Uuid $organizationId, array $projectIds): void
    {
        $connection = $this->db->connection();
        $projects = array_map(static fn(Uuid $id): string => $id->value, $projectIds);

        $connection->select("SELECT set_config('metered.purging_organization', ?, true)", [$organizationId->value]);

        if ($projects !== []) {
            foreach (['ledger_entries', 'ledger_transactions', 'credit_notes', 'invoice_lines', 'invoices'] as $table) {
                $connection->table($table)->whereIn('project_id', $projects)->delete();
            }
        }

        $connection->table('document_sequences')->where('organization_id', $organizationId->value)->delete();
    }
}
