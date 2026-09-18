<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Shared\Application\Metrics\Metrics;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Tracing\Tracing;
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
 * One pass over the stream: reclaim what was abandoned, read what is new,
 * process it, acknowledge what is done with.
 *
 * Acknowledgement comes after the transaction commits, never before. A crash
 * between the two means the batch is delivered again, inserts nothing, and
 * contributes nothing to any aggregate (ADR-0004) — work repeated, nothing
 * double-counted. The opposite order would lose events on a crash, silently,
 * and only under load.
 *
 * Three things can happen to a message. It is processed and acknowledged; it
 * fails and is left unacknowledged, to be reclaimed after it has been idle
 * long enough; or it has failed so many times that it is moved to the
 * dead-letter stream and acknowledged, because one poison message must not
 * hold a tenant's ingestion behind it.
 *
 * Each tenant's write is a span of its own trace, linked to the requests its
 * events came from rather than a child of any one of them (ADR-0012): one
 * write serves many requests, and a span has one parent.
 */
final readonly class StreamConsumer
{
    /**
     * Past this many distinct requests a batch links to the first ones only;
     * the span still records how many there were.
     */
    public const int MAX_LINKS = 128;

    public function __construct(
        private PhpRedisConnection $connection,
        private BatchProcessor $processor,
        private RejectionLog $rejections,
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
     * Creates the consumer group, and the stream with it if it does not exist.
     *
     * `MKSTREAM` matters on a fresh installation: the first consumer usually
     * starts before the first event is sent, and a group cannot be created on
     * a stream that is not there.
     */
    public function ensureGroup(): void
    {
        try {
            // '0' rather than '$': a group starting at the end would ignore
            // everything already waiting, which on a restart is the backlog.
            $this->connection->command('xgroup', ['CREATE', $this->key, $this->group, '0', true]);
        } catch (Throwable $failure) {
            // BUSYGROUP is the normal case on every start but the first.
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

        foreach ($deliveries as $delivery) {
            $envelope = StreamEnvelope::decode($delivery->fields);

            if (! $envelope instanceof StreamEnvelope) {
                $malformedIds[] = $delivery->id;
                $rejection = $this->rejectionFor($delivery);

                if ($rejection instanceof Rejection) {
                    $malformed[] = $rejection;
                }

                continue;
            }

            $key = (string) $envelope->tenant;
            $groups[$key]['tenant'] = $envelope->tenant;
            $groups[$key]['events'][] = new IncomingEvent($envelope->event, $envelope->receivedAt);
            $groups[$key]['ids'][] = $delivery->id;

            // Fifty events of one request carry one context: keyed by it,
            // each request is linked once.
            if (isset($envelope->trace['traceparent'])) {
                $groups[$key]['traces'][$envelope->trace['traceparent']] = $envelope->trace;
            }
        }

        if ($malformed !== []) {
            $this->rejections->record($malformed);
        }

        if ($malformedIds !== []) {
            // Unreadable, so redelivering it would fail the same way forever.
            // It goes to the dead-letter stream with its reason and is
            // acknowledged, and the rejection above is what a tenant sees.
            $this->deadLetter($malformedIds, $deliveries, 'malformed');
            $outcome = $outcome->plus(new IngestionOutcome(rejected: count($malformedIds)));
        }

        foreach ($groups as $group) {
            $outcome = $outcome->plus($this->processGroup($group['tenant'], $group['events'], $group['ids'], array_values($group['traces'] ?? [])));
        }

        return $outcome;
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

        // Only now. Everything above this line is redoable; acknowledging
        // before it would make the failure unrecoverable instead.
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
                'too_many_deliveries',
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

                // The message as it was, plus why it is here and when: a
                // dead-letter entry nobody can diagnose is a dropped message
                // with extra steps.
                $pipe->xadd($key, '*', [
                    ...$delivery->fields,
                    '_reason' => $reason,
                    '_deliveries' => (string) $delivery->deliveries,
                    '_dead_lettered_at' => $at,
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
     * Takes over messages another consumer stopped acknowledging.
     *
     * Pending first, claim second, rather than `XAUTOCLAIM`: the pending
     * listing is what carries the delivery count, and the delivery count is
     * how a poison message is recognised before it is processed again.
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
     * A rejection for a message that could not be read, when it at least said
     * whose it was. When it did not, there is no project to file it under and
     * the dead-letter entry is the only record there can be.
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
