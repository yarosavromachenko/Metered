<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Access;

use RuntimeException;

/**
 * Refused for want of authority — as opposed to refused because the thing
 * asked for does not exist, which is a different answer and a different type.
 *
 * Not a DomainException: nothing about the request breaks a business rule.
 * The same command from the same caller with a different role would be
 * carried out, and code that catches broken rules should not also swallow
 * this.
 */
final class PermissionDenied extends RuntimeException
{
    public static function for(Actor $actor, Permission $permission): self
    {
        return new self(sprintf('%s may not %s.', $actor->label, $permission->value));
    }
}
