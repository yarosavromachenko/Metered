<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Invoicing\Domain\Invoice\BillingHistory;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

final readonly class DatabaseBillingHistory implements BillingHistory
{
    public function __construct(private DatabaseManager $db) {}

    public function periodsEndedAfter(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $after): array
    {
        $rows = $this->db->connection()->table('invoices')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->where('subscription_id', $subscriptionId->value)
            ->where('period_end', '>', $after->format(DatabaseInvoiceRepository::INSTANT))
            ->orderBy('period_start')
            ->get(['period_start', 'period_end']);

        $periods = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $periods[] = InvoicePeriod::between(RowReader::instant($row->period_start, 'period_start'), RowReader::instant($row->period_end, 'period_end'));
            }
        }

        return $periods;
    }

    public function billedQuantities(TenantContext $tenant, Uuid $subscriptionId, InvoicePeriod $covers): array
    {
        // Usage line plus late lines; late lines hold increments, so the sum is
        // the billed quantity (for max meters, the billed peak).
        $rows = $this->db->connection()->table('invoice_lines')
            ->where('project_id', $tenant->projectId->value)
            ->where('subscription_id', $subscriptionId->value)
            ->whereNotNull('meter_id')
            ->where('covers_start', $covers->start->format(DatabaseInvoiceRepository::INSTANT))
            ->where('covers_end', $covers->end->format(DatabaseInvoiceRepository::INSTANT))
            ->groupBy('meter_id')
            ->selectRaw('meter_id::text AS meter_id, sum(quantity)::numeric(38, 6) AS quantity')
            ->get();

        $billed = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $billed[RowReader::string($row->meter_id, 'meter_id')] = Quantity::fromString(RowReader::string($row->quantity, 'quantity'));
            }
        }

        return $billed;
    }
}
