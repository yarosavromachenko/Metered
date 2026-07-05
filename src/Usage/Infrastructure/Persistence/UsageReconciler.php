<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

/**
 * Recomputes aggregates from the raw events and reports where the two
 * disagree.
 *
 * This is the check behind the claim the whole ingestion design makes: an
 * aggregate always equals the fold of the events it covers. It runs in tests,
 * after every chaos scenario, and whenever an operator wants to know. Without
 * it, "exactly once in effect" is a sentence in an ADR rather than a property
 * anybody has verified.
 *
 * The comparison is one query, in the database, because moving tens of
 * millions of rows into PHP to add them up would make the check too expensive
 * to run — and a check nobody runs proves nothing.
 */
final readonly class UsageReconciler
{
    public function __construct(
        private DatabaseManager $db,
        private string $connection,
    ) {}

    /**
     * @return list<Drift>
     */
    public function check(TenantContext $tenant, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->db->connection($this->connection)->select(
            $this->sql(),
            [
                $tenant->projectId->value,
                $from->format('Y-m-d H:i:sP'),
                $to->format('Y-m-d H:i:sP'),
                $tenant->projectId->value,
                $from->format('Y-m-d H:i:sP'),
                $to->format('Y-m-d H:i:sP'),
            ],
        );

        $drifts = [];

        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $values = get_object_vars($row);
            $stored = $values['stored_quantity'] ?? null;
            $recomputed = $values['recomputed_quantity'] ?? null;

            $drifts[] = new Drift(
                kind: $this->kind($stored, $recomputed),
                customerId: RowReader::string($values['customer_id'] ?? null, 'customer_id'),
                meterId: RowReader::string($values['meter_id'] ?? null, 'meter_id'),
                bucketStart: RowReader::string($values['bucket_start'] ?? null, 'bucket_start'),
                storedQuantity: is_string($stored) ? $stored : null,
                recomputedQuantity: is_string($recomputed) ? $recomputed : null,
                storedCount: isset($values['stored_count'])
                    ? RowReader::int($values['stored_count'], 'stored_count')
                    : null,
                recomputedCount: isset($values['recomputed_count'])
                    ? RowReader::int($values['recomputed_count'], 'recomputed_count')
                    : null,
            );
        }

        return $drifts;
    }

    /**
     * Rewrites the aggregates in the window from the events under them.
     *
     * Deliberately not automatic. Drift means something is wrong upstream,
     * and an aggregate quietly repaired every night is a bug that never gets
     * found — so the repair is a decision an operator makes, with the drift
     * report in front of them.
     */
    public function repair(TenantContext $tenant, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $connection = $this->db->connection($this->connection);
        $window = [$from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP')];

        /** @var int $repaired */
        $repaired = $connection->transaction(function () use ($connection, $tenant, $window): int {
            $connection->statement(
                'DELETE FROM usage_aggregates
                 WHERE project_id = ? AND bucket_start >= ? AND bucket_start < ?',
                [$tenant->projectId->value, ...$window],
            );

            $connection->statement(
                'INSERT INTO usage_aggregates
                 (organization_id, project_id, customer_id, meter_id, meter_code, customer_ref,
                  bucket_start, quantity, event_count, updated_at)
                 SELECT ?, project_id, customer_id, meter_id, meter_code, customer_ref,
                        bucket_start, quantity, event_count, now()
                 FROM (' . $this->recomputedSql() . ') AS recomputed',
                [$tenant->organizationId->value, $tenant->projectId->value, ...$window],
            );

            $rows = $connection->selectOne(
                'SELECT count(*) AS rows FROM usage_aggregates
                 WHERE project_id = ? AND bucket_start >= ? AND bucket_start < ?',
                [$tenant->projectId->value, ...$window],
            );

            return $rows instanceof stdClass
                ? RowReader::int(get_object_vars($rows)['rows'] ?? null, 'rows')
                : 0;
        });

        return $repaired;
    }

    private function kind(mixed $stored, mixed $recomputed): string
    {
        if ($stored === null) {
            return Drift::MISSING;
        }

        return $recomputed === null ? Drift::EXTRA : Drift::MISMATCH;
    }

    /**
     * The fold, in SQL, exactly as {@see \Metered\Shared\Domain\Metering\Aggregation::fold()}
     * defines it. Two expressions of one rule is a risk worth naming: an
     * integration test folds the same events both ways and compares, so a
     * change to one that is not made to the other fails rather than drifts.
     */
    private function recomputedSql(): string
    {
        return <<<'SQL'
            SELECT e.project_id,
                   e.customer_id,
                   e.meter_id,
                   min(e.meter_code) AS meter_code,
                   min(e.customer_ref) AS customer_ref,
                   date_trunc('hour', e.occurred_at) AS bucket_start,
                   -- Cast to the aggregate column's own type: numeric
                   -- equality ignores trailing zeros, but a report a person
                   -- reads should not print 3 where the table holds 3.000000.
                   CASE m.aggregation
                       WHEN 'sum' THEN sum(e.quantity)
                       WHEN 'count' THEN count(*)::numeric
                       WHEN 'max' THEN max(e.quantity)
                   END::numeric(38, 6) AS quantity,
                   count(*) AS event_count
            FROM usage_events e
            JOIN meters m ON m.id = e.meter_id
            WHERE e.project_id = ? AND e.occurred_at >= ? AND e.occurred_at < ?
            GROUP BY e.project_id, e.customer_id, e.meter_id, date_trunc('hour', e.occurred_at), m.aggregation
            SQL;
    }

    private function sql(): string
    {
        // A full outer join, because all three kinds of disagreement matter:
        // an aggregate with no events, events with no aggregate, and the two
        // present but disagreeing.
        return <<<SQL
            WITH recomputed AS (
                {$this->recomputedSql()}
            ),
            stored AS (
                SELECT customer_id, meter_id, bucket_start, quantity, event_count
                FROM usage_aggregates
                WHERE project_id = ? AND bucket_start >= ? AND bucket_start < ?
            )
            SELECT COALESCE(s.customer_id::text, r.customer_id::text) AS customer_id,
                   COALESCE(s.meter_id::text, r.meter_id::text) AS meter_id,
                   COALESCE(s.bucket_start, r.bucket_start)::text AS bucket_start,
                   s.quantity::text AS stored_quantity,
                   r.quantity::text AS recomputed_quantity,
                   s.event_count AS stored_count,
                   r.event_count AS recomputed_count
            FROM stored s
            FULL OUTER JOIN recomputed r
              ON r.customer_id = s.customer_id
             AND r.meter_id = s.meter_id
             AND r.bucket_start = s.bucket_start
            WHERE s.quantity IS DISTINCT FROM r.quantity
               OR s.event_count IS DISTINCT FROM r.event_count
            ORDER BY 3, 1, 2
            SQL;
    }
}
