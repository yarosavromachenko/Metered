<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Usage\Application\Stream\Batch;
use Metered\Usage\Application\Stream\EventStream;
use Metered\Usage\Application\Stream\StreamDepth;
use Psr\Clock\ClockInterface;

/**
 * The hot path, and deliberately the shortest use case in the codebase.
 *
 * It stamps the batch, asks whether the system is keeping up, and hands the
 * events to the stream. No database, no catalog lookup, no aggregation: a
 * tenant's own product waits on this call, so everything that can happen
 * later happens later (ADR-0003).
 */
final readonly class IngestEventsHandler
{
    public function __construct(
        private EventStream $stream,
        private StreamDepth $depth,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private int $backpressureThreshold,
        private int $retryAfterSeconds,
    ) {}

    public function handle(IngestEvents $command): IngestionReceipt
    {
        $pending = $this->depth->pending();

        if ($pending >= $this->backpressureThreshold) {
            // Shedding load is a decision, not a failure: accepting a batch
            // the consumer cannot drain trades a fast 503 now for a stream
            // that never recovers.
            throw IngestionOverloaded::atDepth($pending, $this->backpressureThreshold, $this->retryAfterSeconds);
        }

        $batch = new Batch(
            $command->tenant,
            $this->ids->generate(),
            $this->clock->now(),
            $command->events,
        );

        $this->stream->append($batch);

        return new IngestionReceipt($batch->size(), $batch->requestId);
    }
}
