<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Usage\Application\Ingestion\EventWriter;
use Metered\Usage\Application\Ingestion\ResolvedEvent;
use Metered\Usage\Application\Ingestion\WriteOutcome;
use Metered\Usage\Domain\Bucket;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * The write that makes redelivery harmless.
 *
 * One transaction, two steps, and the join between them is the whole idea
 * (ADR-0004): the insert says `ON CONFLICT DO NOTHING RETURNING`, and only the
 * rows it actually returned are folded into aggregates. A redelivered batch
 * inserts nothing, therefore returns nothing, therefore adds nothing — the
 * effect is exactly once even though the delivery is not, which is the only
 * kind of exactly-once that exists.
 *
 * The cost is that the insert has to return its rows, which a blind insert
 * would not. That is paid knowingly, and it is the cheapest correct option
 * available: the alternative is a second pass with its own watermark and its
 * own way of disagreeing with the first.
 */
final readonly class DatabaseEventWriter implements EventWriter
{
    public function __construct(
        private DatabaseManager $db,
        private string $connection,
        private ClockInterface $clock,
    ) {}

    public function write(TenantContext $tenant, array $events): WriteOutcome
    {
        if ($events === []) {
            return new WriteOutcome(0, 0, 0);
        }

        $connection = $this->db->connection($this->connection);

        /** @var WriteOutcome $outcome */
        $outcome = $connection->transaction(function (ConnectionInterface $tx) use ($tenant, $events): WriteOutcome {
            $inserted = $this->insert($tx, $events);
            $deltas = $this->fold($inserted, $events);

            $this->upsertAggregates($tx, $tenant, $deltas);

            return new WriteOutcome(
                inserted: count($inserted),
                duplicates: count($events) - count($inserted),
                bucketsTouched: count($deltas),
            );
        });

        return $outcome;
    }

    /**
     * @param  list<ResolvedEvent>  $events
     * @return list<array{customer_id: string, meter_id: string, quantity: string, occurred_at: string}>
     */
    private function insert(ConnectionInterface $connection, array $events): array
    {
        $placeholders = [];
        $bindings = [];

        foreach ($events as $resolved) {
            $event = $resolved->event;

            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)';
            $bindings[] = $event->id->value;
            $bindings[] = $event->tenant->organizationId->value;
            $bindings[] = $event->tenant->projectId->value;
            $bindings[] = $event->eventId->value;
            $bindings[] = $event->customerId->value;
            $bindings[] = $event->meterId->value;
            $bindings[] = (string) $event->quantity;
            $bindings[] = $event->occurredAt->format('Y-m-d H:i:s.uP');
            $bindings[] = $event->receivedAt->format('Y-m-d H:i:s.uP');
            $properties = json_encode($event->properties->all());
            $bindings[] = is_string($properties) ? $properties : '{}';
        }

        $rows = $connection->select(
            'INSERT INTO usage_events '
            . '(id, organization_id, project_id, event_id, customer_id, meter_id, quantity, occurred_at, received_at, properties) '
            . 'VALUES ' . implode(', ', $placeholders) . ' '
            // The conflict target is the primary key, which is the natural key
            // deduplication turns on (ADR-0002).
            . 'ON CONFLICT (project_id, event_id, occurred_at) DO NOTHING '
            . 'RETURNING customer_id, meter_id, quantity, occurred_at',
            $bindings,
        );

        $returned = [];

        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $values = get_object_vars($row);

            $returned[] = [
                'customer_id' => RowReader::string($values['customer_id'] ?? null, 'customer_id'),
                'meter_id' => RowReader::string($values['meter_id'] ?? null, 'meter_id'),
                'quantity' => RowReader::string($values['quantity'] ?? null, 'quantity'),
                'occurred_at' => RowReader::string($values['occurred_at'] ?? null, 'occurred_at'),
            ];
        }

        return $returned;
    }

    /**
     * Folds the rows that were actually inserted into one delta per bucket.
     *
     * The fold is the domain's ({@see Aggregation::fold}), not SQL's, so the
     * arithmetic a test can reason about and the arithmetic that runs are the
     * same. SQL is left with the part only it can do: merging this delta into
     * whatever another consumer committed a moment ago.
     *
     * @param  list<array{customer_id: string, meter_id: string, quantity: string, occurred_at: string}>  $inserted
     * @param  list<ResolvedEvent>  $events
     * @return list<array{customer_id: string, meter_id: string, bucket: DateTimeImmutable, quantity: Quantity, count: int, aggregation: Aggregation}>
     */
    private function fold(array $inserted, array $events): array
    {
        $aggregations = [];

        foreach ($events as $resolved) {
            $aggregations[$resolved->event->meterId->value] = $resolved->aggregation;
        }

        $deltas = [];

        foreach ($inserted as $row) {
            $aggregation = $aggregations[$row['meter_id']] ?? Aggregation::Sum;
            $bucket = Bucket::containing(RowReader::instant($row['occurred_at'], 'occurred_at'));
            $key = $row['customer_id'] . '|' . $row['meter_id'] . '|' . $bucket->start->format('U');

            $running = $deltas[$key]['quantity'] ?? Quantity::zero();

            $deltas[$key] = [
                'customer_id' => $row['customer_id'],
                'meter_id' => $row['meter_id'],
                'bucket' => $bucket->start,
                'quantity' => $aggregation->fold($running, Quantity::fromString($row['quantity'])),
                'count' => ($deltas[$key]['count'] ?? 0) + 1,
                'aggregation' => $aggregation,
            ];
        }

        return array_values($deltas);
    }

    /**
     * @param  list<array{customer_id: string, meter_id: string, bucket: DateTimeImmutable, quantity: Quantity, count: int, aggregation: Aggregation}>  $deltas
     */
    private function upsertAggregates(ConnectionInterface $connection, TenantContext $tenant, array $deltas): void
    {
        // Two shapes, because two things can be meant by "merge this in".
        // Sum and count accumulate; max keeps whichever is larger, and a
        // redelivery of a smaller value must not lower the peak.
        $additive = [];
        $peak = [];

        foreach ($deltas as $delta) {
            $delta['aggregation'] === Aggregation::Max
                ? $peak[] = $delta
                : $additive[] = $delta;
        }

        $this->merge($connection, $tenant, $additive, 'usage_aggregates.quantity + excluded.quantity');
        $this->merge($connection, $tenant, $peak, 'GREATEST(usage_aggregates.quantity, excluded.quantity)');
    }

    /**
     * @param  list<array{customer_id: string, meter_id: string, bucket: DateTimeImmutable, quantity: Quantity, count: int, aggregation: Aggregation}>  $deltas
     */
    private function merge(
        ConnectionInterface $connection,
        TenantContext $tenant,
        array $deltas,
        string $quantityExpression,
    ): void {
        if ($deltas === []) {
            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $placeholders = [];
        $bindings = [];

        foreach ($deltas as $delta) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            $bindings[] = $tenant->organizationId->value;
            $bindings[] = $tenant->projectId->value;
            $bindings[] = $delta['customer_id'];
            $bindings[] = $delta['meter_id'];
            $bindings[] = $delta['bucket']->format('Y-m-d H:i:sP');
            $bindings[] = (string) $delta['quantity'];
            $bindings[] = $delta['count'];
            $bindings[] = $now;
        }

        $connection->statement(
            'INSERT INTO usage_aggregates '
            . '(organization_id, project_id, customer_id, meter_id, bucket_start, quantity, event_count, updated_at) '
            . 'VALUES ' . implode(', ', $placeholders) . ' '
            . 'ON CONFLICT (project_id, customer_id, meter_id, bucket_start) DO UPDATE SET '
            . 'quantity = ' . $quantityExpression . ', '
            . 'event_count = usage_aggregates.event_count + excluded.event_count, '
            . 'updated_at = excluded.updated_at',
            $bindings,
        );
    }
}
