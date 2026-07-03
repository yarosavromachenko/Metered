<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The hour an aggregate is keyed by.
 *
 * One hour, everywhere, in UTC. Coarser would make a period boundary fall
 * inside a bucket — a subscription anchored at 09:30 would have to split one;
 * finer would multiply the rows invoicing reads without telling anyone
 * anything new. Aggregates are what an invoice is built from, so the
 * granularity is the smallest unit a period boundary can land on cleanly.
 */
final readonly class Bucket
{
    private function __construct(public DateTimeImmutable $start) {}

    public static function containing(DateTimeImmutable $instant): self
    {
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));

        // setTime clears the microseconds along with the minutes; the key has
        // second precision at most, and a stray microsecond would make two
        // aggregates where there should be one.
        return new self($utc->setTime((int) $utc->format('G'), 0));
    }

    public function equals(self $other): bool
    {
        return $this->start->getTimestamp() === $other->start->getTimestamp();
    }
}
