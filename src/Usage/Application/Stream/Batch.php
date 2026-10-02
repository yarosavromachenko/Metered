<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Application\Command\SubmittedEvent;

/**
 * `receivedAt` is stamped here; the consumer checks the acceptance window
 * against it. `requestId` is returned in the 202 and stored on every
 * rejection from the batch.
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
