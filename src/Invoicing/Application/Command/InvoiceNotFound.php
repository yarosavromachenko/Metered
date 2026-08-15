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
}
