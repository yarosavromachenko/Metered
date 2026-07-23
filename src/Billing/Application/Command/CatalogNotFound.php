<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;
use RuntimeException;

/**
 * Something a command names does not exist in the command's project. The
 * same answer whether it does not exist at all or belongs to another tenant,
 * so the error reveals nothing about anyone else's catalog.
 */
final class CatalogNotFound extends RuntimeException
{
    public static function of(string $what, Uuid $id): self
    {
        return new self(sprintf('No %s %s in this project.', $what, $id->value));
    }
}
