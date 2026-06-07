<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Exception;

final class CurrencyMismatch extends DomainException
{
    public static function between(string $left, string $right): self
    {
        return new self(sprintf(
            'Cannot combine %s and %s: this system never converts between currencies.',
            $left,
            $right,
        ));
    }
}
