<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use DateTimeImmutable;
use Metered\Usage\Application\Command\SubmittedEvent;

/**
 * `receivedAt` comes from the endpoint, so a consumer backlog does not make
 * events fall out of the acceptance window.
 */
final readonly class IncomingEvent
{
    public function __construct(
        public SubmittedEvent $event,
        public DateTimeImmutable $receivedAt,
    ) {}
}
