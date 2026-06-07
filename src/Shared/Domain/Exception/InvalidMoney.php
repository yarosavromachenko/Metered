<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Exception;

final class InvalidMoney extends DomainException
{
    public static function unknownCurrency(string $currency): self
    {
        return new self(sprintf('"%s" is not a known ISO 4217 currency code.', $currency));
    }
}
