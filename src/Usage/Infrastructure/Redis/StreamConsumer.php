<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Database\QueryException;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Shared\Application\Metrics\Metrics;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use Metered\Tenancy\Application\Contract\ProjectDirectory;
use Metered\Usage\Application\Ingestion\BatchProcessor;
use Metered\Usage\Application\Ingestion\IncomingEvent;
use Metered\Usage\Application\Ingestion\IngestionOutcome;
use Metered\Usage\Application\Ingestion\RejectionLog;
use Metered\Usage\Application\Metrics\UsageMetrics;
use Metered\Usage\Domain\Rejection;
use Metered\Usage\Domain\RejectionReason;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Clock\ClockInterface;
use Redis;
use Throwable;

/**
 * One pass: reclaim idle pending messages, read new ones, process, then
 * acknowledge after commit (a crash in between causes a harmless redelivery,
 * ADR-0004). After too many deliveries a message is dead-lettered; unreadable
 * messages and messages of deleted projects are dead-lettered at once.
 *
 * Each tenant is written separately; a failure leaves only that tenant's
 * messages pending and is rethrown after the others. Each write is a span
 * linked to the originating requests (ADR-0012).
 */
final readonly class StreamConsumer
{
    /**
     * Span link limit; the span records the total count.
     */
    public const int MAX_LINKS = 128;

    public function __construct(
        private PhpRedisConnection $connection,
        private BatchProcessor $processor,
        private RejectionLog $rejections,
        private ProjectDirectory $projects,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private string $key,
        private string $deadLetterKey,
        private string $group,
        private int $batchSize,
        private int $blockMilliseconds,
        private int $reclaimIdleMilliseconds,
        private int $maxDeliveries,
        private Tracing $tracing,
        private Metrics $metrics,
    ) {}

    /**
     * `MKSTREAM`: the consumer usually starts before the first event.
     */
    public function ensureGroup(): void
    {
        try {
            // '0', not '$': read entries already waiting.
            $this->connection->command('xgroup', ['CREATE', $this->key, $this->group, '0', true]);
        } catch (Throwable $failure) {
            // BUSYGROUP: the group already exists.
            if (! str_contains($failure->getMessage(), 'BUSYGROUP')) {
                throw $failure;
            }
        }
    }

    public function consumeOnce(string $consumer): ConsumeReport
    {
        $reclaimed = $this->reclaim($consumer);
        $fresh = $this->read($consumer);

        $deliveries = [...$reclaimed, ...$fresh];

        if ($deliveries === []) {
            return new ConsumeReport();
        }

        $deadLettered = $this->setAsidePoison($deliveries);
        $outcome = $this->handle(array_values(array_diff_key($deliveries, $deadLettered)));

        return new ConsumeReport(
            read: count($fresh),
            reclaimed: count($reclaimed),
            deadLettered: count($deadLettered),
            outcome: $outcome,
        );
    }

    /**
     * @param  list<Delivery>  $deliveries
     */
    private function handle(array $deliveries): IngestionOutcome
    {
        /** @var array<string, array{tenant: TenantContext, events: list<IncomingEvent>, ids: list<string>, traces?: array<string, array<string, string>>}> $groups */
        $groups = [];
        $outcome = new IngestionOutcome();
        $malformed = [];
        $malformedIds = [];
        /** @var array<string, bool> $existing */
        $existing = [];

        foreach ($deliveries as $delivery) {
            $envelope = StreamEnvelope::decode($delivery->fields);

            if (! $envelope instanceof StreamEnvelope) {
                $malformedIds[] = $delivery->id;
                $rejection = $this->rejectionFor($delivery);

                if ($rejection instanceof Rejection && $this->exists($rejection->tenant, $existing)) {
                    $malformed[] = $rejection;
                }

                continue;
            }

            $key = (string) $envelope->tenant;
            $groups[$key]['tenant'] = $envelope->tenant;
            $groups[$key]['events'][] = new IncomingEvent($envelope->event, $envelope->receivedAt);
            $groups[$key]['ids'][] = $delivery->id;

            // One link per request.
            if (isset($envelope->trace['traceparent'])) {
                $groups[$key]['traces'][$envelope->trace['traceparent']] = $envelope->trace;
            }
        }

        if ($malformed !== []) {
            $this->rejections->record($malformed);
        }

        if ($malformedIds !== []) {
            // Unreadable: dead-letter and acknowledge now.
            $this->deadLetter($malformedIds, $deliveries, DeadLetters::MALFORMED);
            $outcome = $outcome->plus(new IngestionOutcome(rejected: count($malformedIds)));
        }

        $failure = null;

        foreach ($groups as $group) {
            // Project deleted (demo purge or reset): only the dead letter remains.
            if (! $this->exists($group['tenant'], $existing)) {
                $this->deadLetter($group['ids'], $deliveries, DeadLetters::PROJECT_GONE);
                $outcome = $outcome->plus(new IngestionOutcome(rejected: count($group['ids'])));

                continue;
            }

            try {
                $outcome = $outcome->plus($this->writeGroup($group['tenant'], $group['events'], $group['ids'], array_values($group['traces'] ?? [])));
            } catch (Throwable $groupFailure) {
                // Left pending; continue with the other tenants.
                $failure ??= $groupFailure;
            }
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return $outcome;
    }

    /**
     * When the database rejects the data (SQLSTATE 22/23), retries in halves
     * so only the bad event stays pending. Other errors leave the whole batch
     * for redelivery. Retrying is safe (ADR-0002, ADR-0004).
     *
     * @param  list<IncomingEvent>  $events
     * @param  list<string>  $ids  the stream ids of the events, in the same order
     * @param  list<array<string, string>>  $traces
     */
    private function writeGroup(TenantContext $tenant, array $events, array $ids, array $traces): IngestionOutcome
    {
        try {
            return $this->processGroup($tenant, $events, $ids, $traces);
        } catch (QueryException $failure) {
            if (count($events) < 2 || ! $this->refusesTheData($failure)) {
                throw $failure;
            }
        }

        $half = intdiv(count($events), 2);
        $outcome = new IngestionOutcome();
        $refusal = null;

        foreach ([[0, $half], [$half, null]] as [$offset, $length]) {
            try {
                $outcome = $outcome->plus($this->writeGroup(
                    $tenant,
                    array_slice($events, $offset, $length),
                    array_slice($ids, $offset, $length),
                    $traces,
                ));
            } catch (Throwable $halfFailure) {
                $refusal ??= $halfFailure;
            }
        }

        if ($refusal instanceof Throwable) {
            throw $refusal;
        }

        return $outcome;
    }

    /**
     * SQLSTATE class 22 (data) or 23 (constraint).
     */
    private function refusesTheData(QueryException $failure): bool
    {
        return in_array(substr((string) $failure->getCode(), 0, 2), ['22', '23'], true);
    }

    /**
     * @param  list<IncomingEvent>  $events
     * @param  list<string>  $ids
     * @param  list<array<string, string>>  $traces  the contexts of the requests the events came from
     */
    private function processGroup(TenantContext $tenant, array $events, array $ids, array $traces): IngestionOutcome
    {
        $builder = $this->tracing->tracer()
            ->spanBuilder('usage.batch')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.system', 'redis')
            ->setAttribute('messaging.destination.name', $this->key)
            ->setAttribute('messaging.operation.name', 'process')
            ->setAttribute('messaging.batch.message_count', count($events))
            ->setAttribute('metered.project_id', $tenant->projectId->value)
            ->setAttribute('metered.batch.request_count', count($traces));

        foreach (array_slice($traces, 0, self::MAX_LINKS) as $trace) {
            $builder->addLink($this->tracing->spanContextFrom($trace));
        }

        $span = $builder->startSpan();
        $scope = $span->activate();
        $started = hrtime(true);

        try {
            $outcome = $this->processor->process($tenant, $events);
            $this->metrics->record(UsageMetrics::batchWriteDuration(), (int) (hrtime(true) - $started));
            $this->metrics->record(UsageMetrics::batchSize(), count($events));
        } catch (Throwable $failure) {
            $span->recordException($failure);
            $span->setStatus(StatusCode::STATUS_ERROR, $failure->getMessage());

            throw $failure;
        } finally {
            $scope->detach();
            $span->end();
        }

        // Acknowledge only after the commit.
        $this->acknowledge($ids);

        return $outcome;
    }

    /**
     * @param  list<Delivery>  $deliveries
     * @return array<int, Delivery>  the ones moved aside, keyed as they came
     */
    private function setAsidePoison(array $deliveries): array
    {
        $poison = [];

        foreach ($deliveries as $index => $delivery) {
            if ($delivery->deliveries > $this->maxDeliveries) {
                $poison[$index] = $delivery;
            }
        }

        if ($poison !== []) {
            $this->deadLetter(
                array_map(static fn(Delivery $delivery): string => $delivery->id, array_values($poison)),
                $deliveries,
                DeadLetters::TOO_MANY_DELIVERIES,
            );
        }

        return $poison;
    }

    /**
     * @param  list<string>  $ids
     * @param  list<Delivery>  $deliveries
     */
    private function deadLetter(array $ids, array $deliveries, string $reason): void
    {
        $byId = [];

        foreach ($deliveries as $delivery) {
            $byId[$delivery->id] = $delivery;
        }

        $key = $this->deadLetterKey;
        $at = $this->clock->now()->format(DATE_ATOM);

        $this->connection->pipeline(static function (Redis $pipe) use ($ids, $byId, $key, $reason, $at): void {
            foreach ($ids as $id) {
                $delivery = $byId[$id] ?? null;

                if (! $delivery instanceof Delivery) {
                    continue;
                }

                // Original fields plus reason and time.
                $pipe->xadd($key, '*', [
                    ...$delivery->fields,
                    DeadLetters::REASON => $reason,
                    DeadLetters::DELIVERIES => (string) $delivery->deliveries,
                    DeadLetters::DEAD_LETTERED_AT => $at,
                ]);
            }
        });

        $this->acknowledge($ids);
    }

    /**
     * @param  list<string>  $ids
     */
    private function acknowledge(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->connection->command('xack', [$this->key, $this->group, $ids]);
    }

    /**
     * @return list<Delivery>
     */
    private function read(string $consumer): array
    {
        $response = $this->connection->command('xreadgroup', [
            $this->group,
            $consumer,
            [$this->key => '>'],
            $this->batchSize,
            $this->blockMilliseconds,
        ]);

        if (! is_array($response) || ! isset($response[$this->key]) || ! is_array($response[$this->key])) {
            return [];
        }

        $deliveries = [];

        foreach ($response[$this->key] as $id => $fields) {
            $deliveries[] = new Delivery((string) $id, $this->fields($fields), 1);
        }

        return $deliveries;
    }

    /**
     * XPENDING then XCLAIM, not `XAUTOCLAIM`: XPENDING returns the delivery count.
     *
     * @return list<Delivery>
     */
    private function reclaim(string $consumer): array
    {
        $pending = $this->connection->command('xpending', [
            $this->key,
            $this->group,
            '-',
            '+',
            $this->batchSize,
        ]);

        if (! is_array($pending) || $pending === []) {
            return [];
        }

        $counts = [];

        foreach ($pending as $entry) {
            if (! is_array($entry) || ! isset($entry[0], $entry[2], $entry[3])) {
                continue;
            }

            $idle = is_numeric($entry[2]) ? (int) $entry[2] : 0;

            if ($idle < $this->reclaimIdleMilliseconds) {
                continue;
            }

            $id = is_scalar($entry[0]) ? (string) $entry[0] : '';

            if ($id === '') {
                continue;
            }

            $counts[$id] = is_numeric($entry[3]) ? (int) $entry[3] : 1;
        }

        if ($counts === []) {
            return [];
        }

        $claimed = $this->connection->command('xclaim', [
            $this->key,
            $this->group,
            $consumer,
            $this->reclaimIdleMilliseconds,
            array_keys($counts),
        ]);

        if (! is_array($claimed)) {
            return [];
        }

        $deliveries = [];

        foreach ($claimed as $id => $fields) {
            $deliveries[] = new Delivery((string) $id, $this->fields($fields), $counts[(string) $id] ?? 1);
        }

        return $deliveries;
    }

    /**
     * Checked once per tenant per pass.
     *
     * @param  array<string, bool>  $existing
     */
    private function exists(TenantContext $tenant, array &$existing): bool
    {
        $key = (string) $tenant;

        if (! isset($existing[$key])) {
            $found = $this->projects->find($tenant->projectId);
            $existing[$key] = $found instanceof TenantContext && $found->equals($tenant);
        }

        return $existing[$key];
    }

    /**
     * Null when the message names no project.
     */
    private function rejectionFor(Delivery $delivery): ?Rejection
    {
        $organizationId = $delivery->fields['organization_id'] ?? '';
        $projectId = $delivery->fields['project_id'] ?? '';

        if (! Uuid::isValid($organizationId) || ! Uuid::isValid($projectId)) {
            return null;
        }

        return Rejection::of(
            $this->ids->generate(),
            new TenantContext(Uuid::fromString($organizationId), Uuid::fromString($projectId)),
            RejectionReason::Malformed,
            'This event could not be read off the stream, so nothing about it could be checked.',
            $delivery->fields,
            $this->clock->now(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function fields(mixed $fields): array
    {
        $map = [];

        foreach (is_array($fields) ? $fields : [] as $name => $value) {
            $map[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return $map;
    }
}
