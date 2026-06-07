<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Exception;

final class InvalidDecimal extends DomainException
{
    public static function notANumber(string $value): self
    {
        return new self(sprintf('"%s" is not a decimal number.', $value));
    }

    public static function negative(string $value): self
    {
        return new self(sprintf('"%s" is negative, and this value may not be.', $value));
    }

    public static function tooPrecise(string $value, int $scale): self
    {
        return new self(sprintf(
            '"%s" carries more than %d decimal places. Rounding it here would silently '
            . 'change what a customer is billed, so it is refused instead.',
            $value,
            $scale,
        ));
    }
}
