<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class RegisterEndpoint
{
    /**
     * @param list<string> $eventTypes
     */
    public function __construct(
        public TenantContext $tenant,
        public string $url,
        public string $description,
        public array $eventTypes,
        public Actor $actor,
    ) {}
}
