<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use RuntimeException;
use Throwable;

/**
 * The pass stopped because PostgreSQL could not be reached; what it had read
 * stays pending, and its delivery counts were restored.
 */
final class DatabaseUnavailable extends RuntimeException
{
    public static function because(Throwable $cause): self
    {
        return new self('The database could not be reached: ' . $cause->getMessage(), 0, $cause);
    }
}
