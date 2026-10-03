<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Shared\Application\Exception\NotFound;
use Metered\Shared\Domain\Identifier\Uuid;
use RuntimeException;

/**
 * Also for another tenant's invoice.
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
     * Same error as for an unknown id.
     */
    public static function reference(string $given): self
    {
        return new self(sprintf('No invoice %s in this project.', $given));
    }
}
