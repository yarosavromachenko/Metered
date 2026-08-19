<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Shared\Application\Exception\NotFound;
use Metered\Shared\Domain\Identifier\Uuid;
use RuntimeException;

/**
 * No such invoice in the command's project — the same answer whether it does
 * not exist or belongs to another tenant.
 */
final class InvoiceNotFound extends RuntimeException implements NotFound
{
    public static function of(Uuid $id): self
    {
        return new self(sprintf('No invoice %s in this project.', $id->value));
    }

    public static function subscription(Uuid $id): self
    {
        return new self(sprintf('No subscription %s in this project.', $id->value));
    }

    /**
     * For an id that is not even a UUID: the same answer as for one that
     * does not exist, rather than a validation error that tells them apart.
     */
    public static function reference(string $given): self
    {
        return new self(sprintf('No invoice %s in this project.', $given));
    }
}
