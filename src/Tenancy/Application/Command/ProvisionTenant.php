<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\Environment;

/**
 * Organization, first project and first key in one command. `ownerUserId` is
 * null from the console. `demo` lets demo mode delete the tenant when idle.
 */
final readonly class ProvisionTenant
{
    public function __construct(
        public string $organizationName,
        public Actor $actor,
        public ?Uuid $ownerUserId = null,
        public string $projectName = 'Production',
        public Environment $environment = Environment::Test,
        public string $currency = 'EUR',
        public bool $demo = false,
    ) {}
}
