<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Decimal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Metered\Shared\Domain\Exception\InvalidDecimal;

/**
 * Parsing shared by the decimal value objects.
 *
 * Both rules here are deliberate and both refuse rather than repair. A value
 * carrying more decimal places than the column can hold is rejected instead of
 * rounded, because silently rounding a quantity changes what a customer is
 * billed and leaves no trace that it happened.
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
