<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Application\Command\SubmittedEvent;

/**
 * One accepted request, on its way to the stream.
 *
 * `receivedAt` is stamped once, here, and travels with the events. It is what
 * the consumer judges the acceptance window against — not the time it happens
 * to read the message. Judging at consumption would mean a stream that fell
 * ten minutes behind started rejecting events for being ten minutes too old,
 * which is our backlog punishing the client for our problem.
 *
 * `requestId` is what the client is given in the 202. It is the handle for
 * "what happened to the batch I sent?", and it travels into every rejection
 * the batch produces.
 */
final readonly class Batch
{
    /**
     * @param  list<SubmittedEvent>  $events
     */
    public function __construct(
        public TenantContext $tenant,
        public Uuid $requestId,
        public DateTimeImmutable $receivedAt,
        public array $events,
    ) {}

    public function size(): int
    {
        return count($this->events);
    }
}
