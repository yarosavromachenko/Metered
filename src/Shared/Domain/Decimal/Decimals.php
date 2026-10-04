<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Decimal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Metered\Shared\Domain\Exception\InvalidDecimal;

/**
 * Parsing for the decimal value objects. Negative values and values with more
 * decimal places than the column holds are rejected, not rounded: rounding a
 * quantity would change the bill.
 */
final class Decimals
{
    public static function nonNegative(string $value, int $scale): BigDecimal
    {
        $trimmed = trim($value);

        try {
            $decimal = BigDecimal::of($trimmed);
        } catch (MathException) {
            throw InvalidDecimal::notANumber($value);
        }

        if ($decimal->isNegative()) {
            throw InvalidDecimal::negative($trimmed);
        }

        if ($decimal->getScale() > $scale) {
            throw InvalidDecimal::tooPrecise($trimmed, $scale);
        }

        return $decimal->toScale($scale);
    }
}
