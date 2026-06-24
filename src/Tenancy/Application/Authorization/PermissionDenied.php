<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Authorization;

use Metered\Tenancy\Domain\Permission;
use RuntimeException;

final class PermissionDenied extends RuntimeException
{
    public static function for(Actor $actor, Permission $permission): self
    {
        return new self(sprintf('%s may not %s.', $actor->label, $permission->value));
    }
}
