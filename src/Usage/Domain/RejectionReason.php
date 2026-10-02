<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

/**
 * Stored with each rejection (ADR-0003) and counted as a metric.
 */
enum RejectionReason: string
{
    /** Not readable as an event. */
    case Malformed = 'malformed';

    /** No meter with this code in the project. */
    case UnknownMeter = 'unknown_meter';

    /** No customer with this reference in the project. */
    case UnknownCustomer = 'unknown_customer';

    /** Older than the acceptance window. */
    case TooOld = 'too_old';

    /** Too far in the future. */
    case InTheFuture = 'in_the_future';

    public function label(): string
    {
        return match ($this) {
            self::Malformed => 'Malformed event',
            self::UnknownMeter => 'Unknown meter',
            self::UnknownCustomer => 'Unknown customer',
            self::TooOld => 'Outside the acceptance window',
            self::InTheFuture => 'Timestamp in the future',
        };
    }
}
