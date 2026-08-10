<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvoiceTransitionRefused extends DomainException
{
    public static function from(string $status, string $action): self
    {
        return new self(sprintf('A %s invoice cannot be %s.', $status, $action));
    }
}
