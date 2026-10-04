<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use Brick\Math\BigDecimal;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Usage\Domain\Exception\InvalidEventQuantity;

/**
 * Bounded by the `numeric(20, 6)` column: a larger value is a 422 here instead
 * of a failed write for the whole batch later.
 */
final class EventQuantity
{
    /** Exclusive. */
    public const string LIMIT = '100000000000000';

    public static function fromString(string $value): Quantity
    {
        $quantity = Quantity::fromString($value);

        if ($quantity->toBigDecimal()->isGreaterThanOrEqualTo(BigDecimal::of(self::LIMIT))) {
            throw InvalidEventQuantity::tooLarge(trim($value), self::LIMIT);
        }

        return $quantity;
    }
}
