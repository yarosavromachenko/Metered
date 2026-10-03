<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Shared\Domain\Exception\DomainException;

/**
 * Nothing booked, the invoice stays open. The message is the provider's.
 */
final class PaymentDeclined extends DomainException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('The payment was declined: %s', $reason));
    }
}
