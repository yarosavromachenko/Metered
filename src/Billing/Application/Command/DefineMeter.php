<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class DefineMeter
{
    public function __construct(
        public TenantContext $tenant,
        public string $code,
        public string $name,
        public Aggregation $aggregation,
        public Actor $actor,
    ) {}
}
