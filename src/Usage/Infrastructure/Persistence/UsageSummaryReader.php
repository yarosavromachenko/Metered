<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

/**
 * What a customer has used, read from the aggregates.
 *
 * The read side, and it says so: a query builder against the tables, no
 * repository, no mapping into domain objects on the way to being serialised
 * back out. There is nothing to protect here — reads cannot break an
 * invariant — and the shape of the answer is the shape of the response.
 *
 * Totals are folded the same way the buckets were: added for `sum` and
 * `count`, and taken as the peak for `max`. Adding hourly peaks together
 * would produce a number that means nothing and looks plausible.
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
        string $customerReference,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $meterCode = null,
    ): array {
        // One join, to meters, and only for the aggregation mode: the
        // aggregate already carries the code and the reference it was folded
        // under, so neither label needs looking up.
        $query = $this->db->connection($this->connection)
            ->table('usage_aggregates as a')
            ->join('meters as m', 'm.id', '=', 'a.meter_id')
            ->where('a.project_id', $tenant->projectId->value)
            ->where('a.organization_id', $tenant->organizationId->value)
            ->where('a.customer_ref', $customerReference)
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
