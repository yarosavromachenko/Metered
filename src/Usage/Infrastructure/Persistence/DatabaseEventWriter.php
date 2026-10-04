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
 * One transaction: `INSERT ... ON CONFLICT DO NOTHING RETURNING`, then only
 * the returned rows are folded into aggregates, so a redelivered batch adds
 * nothing (ADR-0004).
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
     * @return list<array{customer_id: string, meter_id: string, meter_code: string, customer_ref: string, quantity: string, occurred_at: string}>
     */
    private function insert(ConnectionInterface $connection, array $events): array
    {
        $placeholders = [];
        $bindings = [];

        foreach ($events as $resolved) {
            $event = $resolved->event;

            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)';
            $bindings[] = $event->id->value;
            $bindings[] = $event->tenant->organizationId->value;
            $bindings[] = $event->tenant->projectId->value;
            $bindings[] = $event->eventId->value;
            $bindings[] = $event->customerId->value;
            $bindings[] = $event->meterId->value;
            $bindings[] = $resolved->meterCode;
            $bindings[] = $resolved->customerReference;
            $bindings[] = (string) $event->quantity;
            $bindings[] = $event->occurredAt->format('Y-m-d H:i:s.uP');
            $bindings[] = $event->receivedAt->format('Y-m-d H:i:s.uP');
            $properties = json_encode($event->properties->all());
            $bindings[] = is_string($properties) ? $properties : '{}';
        }

        $rows = $connection->select(
            'INSERT INTO usage_events '
            . '(id, organization_id, project_id, event_id, customer_id, meter_id, meter_code, customer_ref, '
            . 'quantity, occurred_at, received_at, properties) '
            . 'VALUES ' . implode(', ', $placeholders) . ' '
            // Conflict on the primary key, the deduplication key (ADR-0002).
            . 'ON CONFLICT (project_id, event_id, occurred_at) DO NOTHING '
            . 'RETURNING customer_id, meter_id, meter_code, customer_ref, quantity, occurred_at',
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
                'meter_code' => RowReader::string($values['meter_code'] ?? null, 'meter_code'),
                'customer_ref' => RowReader::string($values['customer_ref'] ?? null, 'customer_ref'),
                'quantity' => RowReader::string($values['quantity'] ?? null, 'quantity'),
                'occurred_at' => RowReader::string($values['occurred_at'] ?? null, 'occurred_at'),
            ];
        }

        return $returned;
    }

    /**
     * One delta per bucket, folded with {@see Aggregation::fold}; SQL only
     * merges it into the stored aggregate.
     *
     * @param  list<array{customer_id: string, meter_id: string, meter_code: string, customer_ref: string, quantity: string, occurred_at: string}>  $inserted
     * @param  list<ResolvedEvent>  $events
     * @return list<array{customer_id: string, meter_id: string, meter_code: string, customer_ref: string, bucket: DateTimeImmutable, quantity: Quantity, count: int, aggregation: Aggregation}>
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
                'meter_code' => $row['meter_code'],
                'customer_ref' => $row['customer_ref'],
                'bucket' => $bucket->start,
                'quantity' => $aggregation->fold($running, Quantity::fromString($row['quantity'])),
                'count' => ($deltas[$key]['count'] ?? 0) + 1,
                'aggregation' => $aggregation,
            ];
        }

        return array_values($deltas);
    }

    /**
     * @param  list<array{customer_id: string, meter_id: string, meter_code: string, customer_ref: string, bucket: DateTimeImmutable, quantity: Quantity, count: int, aggregation: Aggregation}>  $deltas
     */
    private function upsertAggregates(ConnectionInterface $connection, TenantContext $tenant, array $deltas): void
    {
        // Sum and count add; max keeps the larger value.
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
     * @param  list<array{customer_id: string, meter_id: string, meter_code: string, customer_ref: string, bucket: DateTimeImmutable, quantity: Quantity, count: int, aggregation: Aggregation}>  $deltas
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
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $bindings[] = $tenant->organizationId->value;
            $bindings[] = $tenant->projectId->value;
            $bindings[] = $delta['customer_id'];
            $bindings[] = $delta['meter_id'];
            $bindings[] = $delta['meter_code'];
            $bindings[] = $delta['customer_ref'];
            $bindings[] = $delta['bucket']->format('Y-m-d H:i:sP');
            $bindings[] = (string) $delta['quantity'];
            $bindings[] = $delta['count'];
            $bindings[] = $now;
        }

        $connection->statement(
            'INSERT INTO usage_aggregates '
            . '(organization_id, project_id, customer_id, meter_id, meter_code, customer_ref, '
            . 'bucket_start, quantity, event_count, updated_at) '
            . 'VALUES ' . implode(', ', $placeholders) . ' '
            . 'ON CONFLICT (project_id, customer_id, meter_id, bucket_start) DO UPDATE SET '
            . 'quantity = ' . $quantityExpression . ', '
            . 'event_count = usage_aggregates.event_count + excluded.event_count, '
            . 'updated_at = excluded.updated_at',
            $bindings,
        );
    }
}
