<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Usage\Application\Stream\Batch;
use Metered\Usage\Application\Stream\EventStream;
use Metered\Usage\Application\Stream\StreamDepth;
use Metered\Usage\Application\Stream\StreamFull;
use Psr\Clock\ClockInterface;

/**
 * Hot path: stamps the batch, checks backpressure and appends to the stream.
 * No database access (ADR-0003).
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
            // 503 now instead of a backlog the consumer cannot drain.
            throw IngestionOverloaded::atDepth($pending, $this->backpressureThreshold, $this->retryAfterSeconds);
        }

        $batch = new Batch(
            $command->tenant,
            $this->ids->generate(),
            $this->clock->now(),
            $command->events,
        );

        try {
            $this->stream->append($batch);
        } catch (StreamFull) {
            throw IngestionOverloaded::outOfMemory($this->retryAfterSeconds);
        }

        return new IngestionReceipt($batch->size(), $batch->requestId);
    }
}
