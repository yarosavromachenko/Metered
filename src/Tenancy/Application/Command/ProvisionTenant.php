<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Authorization\Actor;
use Metered\Tenancy\Domain\Environment;

/**
 * Create an organization, its first project and the key that reaches it.
 *
 * One command rather than three, because a tenant with no project or a
 * project with no key is not a tenant anyone can use — it is a half-finished
 * state that would have to be repaired by hand.
 *
 * `ownerUserId` is the person who ends up owning it, when there is one. The
 * console has nobody; a sign-up has exactly the person who filled in the form.
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
    ) {}
}
