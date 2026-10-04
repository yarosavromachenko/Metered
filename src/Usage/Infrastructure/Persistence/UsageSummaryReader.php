<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

/**
 * Query-side read from aggregates, no domain mapping. Sum and count are
 * added; max takes the peak.
 */
final readonly class UsageSummaryReader
{
    public function __construct(
        private DatabaseManager $db,
        private string $connection,
    ) {}

    /**
     * @return list<array{meter_code: string, aggregation: string, quantity: string, events: int}>
     */
    public function forCustomer(
        TenantContext $tenant,
        Uuid $customerId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $meterCode = null,
    ): array {
        // Joined only for the aggregation mode; codes are on the aggregate.
        $query = $this->db->connection($this->connection)
            ->table('usage_aggregates as a')
            ->join('meters as m', 'm.id', '=', 'a.meter_id')
            ->where('a.project_id', $tenant->projectId->value)
            ->where('a.organization_id', $tenant->organizationId->value)
            // By id: second primary key column, so this is a range scan.
            ->where('a.customer_id', $customerId->value)
            ->where('a.bucket_start', '>=', $from->format('Y-m-d H:i:sP'))
            ->where('a.bucket_start', '<', $to->format('Y-m-d H:i:sP'))
            ->groupBy('a.meter_code', 'm.aggregation')
            ->orderBy('a.meter_code')
            ->selectRaw(
                "a.meter_code AS meter_code,
                 m.aggregation AS aggregation,
                 CASE m.aggregation
                     WHEN 'max' THEN max(a.quantity)
                     ELSE sum(a.quantity)
                 END::numeric(38, 6) AS quantity,
                 sum(a.event_count) AS events",
            );

        if ($meterCode !== null) {
            $query->where('a.meter_code', $meterCode);
        }

        $summary = [];

        foreach ($query->get() as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $values = get_object_vars($row);

            $summary[] = [
                'meter_code' => RowReader::string($values['meter_code'] ?? null, 'meter_code'),
                'aggregation' => RowReader::string($values['aggregation'] ?? null, 'aggregation'),
                'quantity' => RowReader::string($values['quantity'] ?? null, 'quantity'),
                'events' => RowReader::int($values['events'] ?? null, 'events'),
            ];
        }

        return $summary;
    }
}
