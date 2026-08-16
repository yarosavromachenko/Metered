<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Shared\Domain\Exception\DomainException;

/**
 * The provider refused to collect. Nothing was booked and the invoice is
 * still open; the reason is the provider's, shown as it was given.
 */
final class PaymentDeclined extends DomainException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('The payment was declined: %s', $reason));
    }
}
