<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Access;

use RuntimeException;

/**
 * Not a DomainException: the request breaks no business rule, the caller just
 * lacks the role.
 */
final class PermissionDenied extends RuntimeException
{
    public static function for(Actor $actor, Permission $permission): self
    {
        return new self(sprintf('%s may not %s.', $actor->label, $permission->value));
    }
}
