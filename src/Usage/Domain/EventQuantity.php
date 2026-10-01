<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use Brick\Math\BigDecimal;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Usage\Domain\Exception\InvalidEventQuantity;

/**
 * The quantity one event may carry.
 *
 * A quantity in general has no upper bound: totals and invoice lines are
 * stored in `numeric(38, 6)`. One event is stored in `numeric(20, 6)`, which
 * holds fourteen digits before the point. A larger value would be accepted
 * with a 202 and then fail the write, and with it every event of the tenant
 * written in the same batch; refused here, the client gets a 422 that names
 * the event instead.
 */
final class EventQuantity
{
    /** Exclusive: the column holds 99999999999999.999999 and nothing above. */
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
