<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Metering;

use Metered\Shared\Domain\Quantity\Quantity;

/**
 * How the events on a meter become one number.
 *
 * Three modes, and the shortness of the list is the design. Billing defines
 * meters, Usage folds events into aggregates, Invoicing prices what the fold
 * produced — so the vocabulary belongs to none of them and lives in the
 * kernel, like TenantContext and Money.
 *
 * All three are commutative, which is not an accident: delivery from the
 * stream is at-least-once and unordered, and the exactly-once *effect*
 * (ADR-0004) holds only while the order events arrive in cannot change the
 * total. An order-dependent mode — "last value wins", say — would quietly
 * break that, which is why adding one is a decision and not a feature.
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
     * Folds one event into what the bucket holds so far.
     *
     * The database does this same arithmetic in SQL when it upserts an
     * aggregate; this is the definition the upsert must agree with, and an
     * integration test compares the two rather than trusting that they match.
     */
    public function fold(Quantity $running, Quantity $event): Quantity
    {
        return match ($this) {
            self::Sum => $running->plus($event),
            // A count meter counts occurrences, so the quantity carried by the
            // event is deliberately ignored — an event with a quantity of zero
            // still happened.
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
