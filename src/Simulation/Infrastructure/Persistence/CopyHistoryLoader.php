<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Simulation\Application\Port\HistoryLoader;
use Metered\Simulation\Application\Port\LoadedHistory;
use Pdo\Pgsql;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * History by COPY, the documented exception to "the simulation goes through
 * the API" (ADR-0016).
 *
 * Events are streamed into `usage_events` in chunks, and each customer's
 * aggregates are folded as their events go by — with the kernel's own
 * Aggregation::fold(), the definition the consumer's upsert agrees with — and
 * copied into `usage_aggregates` the moment the next customer begins. Neither
 * is derived from the other in SQL, which is what makes the reconciliation
 * afterwards a check rather than a tautology: `usage:reconcile` recomputes
 * every bucket in the window from the events and must find nothing to say.
 *
 * Partitions are created for the history's days first, or everything would
 * land in the default partition. The tenant is found by its key's prefix,
 * the same lookup authentication makes.
 */
final readonly class CopyHistoryLoader implements HistoryLoader
{
    private const int CHUNK = 20_000;

    private const string EVENT_COLUMNS = 'id,organization_id,project_id,event_id,customer_id,meter_id,meter_code,customer_ref,quantity,occurred_at,received_at,properties';

    private const string AGGREGATE_COLUMNS = 'organization_id,project_id,customer_id,meter_id,meter_code,customer_ref,bucket_start,quantity,event_count,updated_at';

    public function __construct(
        private DatabaseManager $db,
        private Kernel $console,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
    ) {}

    public function load(string $token, iterable $events, DateTimeImmutable $from, DateTimeImmutable $to): LoadedHistory
    {
        $connection = $this->db->connection();
        [$organization, $project] = $this->tenant($connection, $token);
        $meters = $this->meters($connection, $project);
        $customers = $this->customers($connection, $project);

        $this->console->call('usage:partitions:ensure', [
            '--back' => (string) ((int) ceil(($this->clock->now()->getTimestamp() - $from->getTimestamp()) / 86_400) + 1),
        ]);

        $counts = $connection->transaction(function () use ($connection, $events, $organization, $project, $meters, $customers): array {
            $pdo = $connection->getPdo();

            if (! $pdo instanceof Pgsql) {
                throw new RuntimeException('The history is loaded with COPY, which needs PostgreSQL.');
            }

            $rows = [];
            $folded = [];
            $current = null;
            $eventCount = 0;
            $aggregateCount = 0;

            foreach ($events as $event) {
                if ($event->customerRef !== $current) {
                    $aggregateCount += $this->copyAggregates($pdo, $folded, $organization, $project);
                    $folded = [];
                    $current = $event->customerRef;
                }

                [$meterId, $aggregation] = $meters[$event->meterCode] ?? throw new RuntimeException(sprintf('No meter "%s" in the project.', $event->meterCode));
                $customerId = $customers[$event->customerRef] ?? throw new RuntimeException(sprintf('No customer "%s" in the project.', $event->customerRef));

                $rows[] = implode("\t", [
                    $this->ids->generate()->value, $organization, $project, $event->eventId, $customerId, $meterId,
                    $event->meterCode, $event->customerRef, $event->quantity,
                    $this->instant($event->occurredAt), $this->instant($event->occurredAt->modify('+2 seconds')), '{}',
                ]);

                $bucket = $event->occurredAt->setTime((int) $event->occurredAt->format('G'), 0);
                $key = $meterId . '|' . $bucket->getTimestamp();
                $folded[$key] ??= ['meter' => $meterId, 'code' => $event->meterCode, 'customer' => $customerId, 'ref' => $event->customerRef, 'bucket' => $bucket, 'aggregation' => $aggregation, 'quantity' => Quantity::zero(), 'events' => 0];
                $folded[$key]['quantity'] = $aggregation->fold($folded[$key]['quantity'], Quantity::fromString($event->quantity));
                ++$folded[$key]['events'];

                if (count($rows) >= self::CHUNK) {
                    $eventCount += $this->copy($pdo, 'usage_events', $rows, self::EVENT_COLUMNS);
                    $rows = [];
                }
            }

            $eventCount += $this->copy($pdo, 'usage_events', $rows, self::EVENT_COLUMNS);
            $aggregateCount += $this->copyAggregates($pdo, $folded, $organization, $project);

            return [$eventCount, $aggregateCount];
        });

        $reconciled = $this->console->call('usage:reconcile', [
            '--project' => $project,
            '--from' => $from->format(DATE_ATOM),
            '--to' => $to->format(DATE_ATOM),
        ]) === 0;

        return new LoadedHistory($counts[0], $counts[1], $reconciled);
    }

    /**
     * @param  array<string, array{meter: string, code: string, customer: string, ref: string, bucket: DateTimeImmutable, aggregation: Aggregation, quantity: Quantity, events: int}>  $folded
     */
    private function copyAggregates(Pgsql $pdo, array $folded, string $organization, string $project): int
    {
        $now = $this->instant($this->clock->now());
        $rows = [];

        foreach ($folded as $aggregate) {
            $rows[] = implode("\t", [
                $organization, $project, $aggregate['customer'], $aggregate['meter'], $aggregate['code'], $aggregate['ref'],
                $this->instant($aggregate['bucket']), (string) $aggregate['quantity'], (string) $aggregate['events'], $now,
            ]);
        }

        return $this->copy($pdo, 'usage_aggregates', $rows, self::AGGREGATE_COLUMNS);
    }

    /**
     * @param  list<string>  $rows
     */
    private function copy(Pgsql $pdo, string $table, array $rows, string $columns): int
    {
        if ($rows === []) {
            return 0;
        }

        if (! $pdo->copyFromArray($table, $rows, "\t", '\\N', $columns)) {
            throw new RuntimeException(sprintf('COPY into %s failed.', $table));
        }

        return count($rows);
    }

    /**
     * @return array{string, string} organization and project ids
     */
    private function tenant(ConnectionInterface $connection, string $token): array
    {
        $prefix = explode('_', $token)[2] ?? '';
        $row = $connection->table('api_keys')->where('prefix', $prefix)->whereNull('revoked_at')->first(['organization_id', 'project_id']);
        $values = is_object($row) ? get_object_vars($row) : [];

        if (! is_string($values['organization_id'] ?? null) || ! is_string($values['project_id'] ?? null)) {
            throw new RuntimeException('No live API key has that prefix.');
        }

        return [$values['organization_id'], $values['project_id']];
    }

    /**
     * @return array<string, array{string, Aggregation}> code => [id, aggregation]
     */
    private function meters(ConnectionInterface $connection, string $project): array
    {
        $meters = [];

        foreach ($connection->table('meters')->where('project_id', $project)->get(['id', 'code', 'aggregation']) as $row) {
            $values = get_object_vars($row);
            $meters[RowReader::string($values['code'] ?? null, 'code')] = [
                RowReader::string($values['id'] ?? null, 'id'),
                Aggregation::from(RowReader::string($values['aggregation'] ?? null, 'aggregation')),
            ];
        }

        return $meters;
    }

    /**
     * @return array<string, string> reference => id
     */
    private function customers(ConnectionInterface $connection, string $project): array
    {
        $customers = [];

        foreach ($connection->table('customers')->where('project_id', $project)->get(['id', 'reference']) as $row) {
            $values = get_object_vars($row);
            $customers[RowReader::string($values['reference'] ?? null, 'reference')] = RowReader::string($values['id'] ?? null, 'id');
        }

        return $customers;
    }

    private function instant(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }
}
