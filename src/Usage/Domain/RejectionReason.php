<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

/**
 * Why an event was not counted.
 *
 * Ingestion answers `202` before any of this is known (ADR-0003), so a
 * rejection is not an HTTP status a client can read — it is a stored fact,
 * countable as a metric and visible in the panel and the API. The list is
 * closed on purpose: "rejected for some reason" is exactly the answer that
 * makes a tenant open a support ticket.
 */
enum RejectionReason: string
{
    /** The message could not be read as an event at all. */
    case Malformed = 'malformed';

    /** No meter in this project answers to the code the event named. */
    case UnknownMeter = 'unknown_meter';

    /** No customer in this project answers to the reference the event named. */
    case UnknownCustomer = 'unknown_customer';

    /** Older than the acceptance window: its period may already be invoiced. */
    case TooOld = 'too_old';

    /** Further ahead than a clock could plausibly drift. */
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
