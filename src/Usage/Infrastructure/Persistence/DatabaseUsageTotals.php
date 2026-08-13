<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Usage\Application\Contract\UsageTotals;
use stdClass;

/**
 * The invoice line build of docs/query-plans.md (4): one customer's buckets
 * over one period, as a range on the aggregate's primary key.
 */
final readonly class DatabaseUsageTotals implements UsageTotals
{
    /**
     * Microseconds kept: a period starts wherever its subscription was
     * anchored, and a bound rounded to the second could move a bucket across it.
     */
    private const string INSTANT = 'Y-m-d H:i:s.uP';

    public function __construct(private DatabaseManager $db) {}

    public function forPeriod(TenantContext $tenant, Uuid $customerId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->db->connection()
            ->table('usage_aggregates as a')
            ->join('meters as m', 'm.id', '=', 'a.meter_id')
            ->where('a.project_id', $tenant->projectId->value)
            ->where('a.organization_id', $tenant->organizationId->value)
            ->where('a.customer_id', $customerId->value)
            ->where('a.bucket_start', '>=', $from->format(self::INSTANT))
            ->where('a.bucket_start', '<', $to->format(self::INSTANT))
            ->groupBy('a.meter_id', 'm.aggregation')
            ->selectRaw(
                "a.meter_id::text AS meter_id,
                 CASE m.aggregation
                     WHEN 'max' THEN max(a.quantity)
                     ELSE sum(a.quantity)
                 END::numeric(38, 6) AS quantity",
            )
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $values = get_object_vars($row);
                $totals[RowReader::string($values['meter_id'] ?? null, 'meter_id')] = Quantity::fromString(RowReader::string($values['quantity'] ?? null, 'quantity'));
            }
        }

        return $totals;
    }
}
