<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Shared\Application\Exception\NotFound;
use Metered\Shared\Domain\Identifier\Uuid;
use RuntimeException;

/**
 * Also for another tenant's rows, so nothing about them is revealed.
 */
final class CatalogNotFound extends RuntimeException implements NotFound
{
    public static function of(string $what, Uuid $id): self
    {
        return new self(sprintf('No %s %s in this project.', $what, $id->value));
    }
}
