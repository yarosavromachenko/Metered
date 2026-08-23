<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class ReconfigureEndpoint
{
    /**
     * @param list<string> $eventTypes
     */
    public function __construct(
        public TenantContext $tenant,
        public Uuid $endpointId,
        public string $url,
        public string $description,
        public array $eventTypes,
        public bool $enabled,
        public Actor $actor,
    ) {}
}
