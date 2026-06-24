<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Authorization\Actor;

final readonly class RevokeApiKey
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $keyId,
        public Actor $actor,
    ) {}
}
