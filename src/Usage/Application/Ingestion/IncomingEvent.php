<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use DateTimeImmutable;
use Metered\Usage\Application\Command\SubmittedEvent;

/**
 * One event off the stream: what the client sent, and when we took it.
 *
 * `receivedAt` travels from the endpoint rather than being read from a clock
 * here, and the acceptance window is judged against it. Otherwise a stream
 * that fell behind would start rejecting events for being too old by exactly
 * the amount it was behind.
 */
final readonly class IncomingEvent
{
    public function __construct(
        public SubmittedEvent $event,
        public DateTimeImmutable $receivedAt,
    ) {}
}
