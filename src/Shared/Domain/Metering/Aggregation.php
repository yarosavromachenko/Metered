<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Metering;

use Metered\Shared\Domain\Quantity\Quantity;

/**
 * How a meter's events become one number. Every mode is commutative: the
 * stream delivers out of order, and ADR-0004 relies on order not changing the
 * total.
 */
enum Aggregation: string
{
    /** Add the quantities: gigabytes transferred, credits spent. */
    case Sum = 'sum';

    /** Count the events, whatever they carry: API calls, messages sent. */
    case Count = 'count';

    /** Keep the highest quantity seen: seats in use, peak concurrency. */
    case Max = 'max';

    /**
     * Same arithmetic as the SQL upsert of an aggregate; an integration test
     * checks the two agree.
     */
    public function fold(Quantity $running, Quantity $event): Quantity
    {
        return match ($this) {
            self::Sum => $running->plus($event),
            // The event's quantity is ignored; a zero-quantity event still counts.
            self::Count => $running->plus(Quantity::fromString('1')),
            self::Max => $running->max($event),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sum => 'Sum of quantities',
            self::Count => 'Count of events',
            self::Max => 'Highest quantity',
        };
    }
}
