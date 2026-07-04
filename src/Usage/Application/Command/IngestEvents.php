<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class IngestEvents
{
    /**
     * @param  list<SubmittedEvent>  $events
     */
    public function __construct(
        public TenantContext $tenant,
        public array $events,
    ) {}
}
