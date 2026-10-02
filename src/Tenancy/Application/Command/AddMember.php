<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Domain\Slug;
use SensitiveParameter;

/**
 * Operator command. The demo uses it for the read-only showcase login.
 */
final readonly class AddMember
{
    public function __construct(
        public Slug $organization,
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $plainPassword,
        public Role $role,
        public Actor $actor,
    ) {}
}
