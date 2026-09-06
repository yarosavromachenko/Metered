<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Domain\Slug;
use SensitiveParameter;

/**
 * Give a new person a role in an existing organization — an operator's
 * command, as organizations are created by one. The demo uses it for the
 * read-only account a reviewer signs in with before signing up.
 */
final readonly class AddMember
{
    public function __construct(
        public Slug $organization,
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $password,
        public Role $role,
        public Actor $actor,
    ) {}
}
