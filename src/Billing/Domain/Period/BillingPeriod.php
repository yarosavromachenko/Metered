<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Period;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Billing\Domain\Exception\InvalidPeriod;
use Stringable;

/**
 * A half-open interval of time, `[start, end)`, that one invoice covers.
 *
 * Half-open so that consecutive periods share a boundary without sharing an
 * instant: an event at exactly midnight belongs to the period that starts
 * then, never to both and never to neither.
 */
final readonly class BillingPeriod implements Stringable
{
    private function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}

    public function __toString(): string
    {
        return sprintf('[%s, %s)', $this->start->format('Y-m-d\TH:i:s\Z'), $this->end->format('Y-m-d\TH:i:s\Z'));
    }

    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        if ($end <= $start) {
            throw InvalidPeriod::notAfterStart($start, $end);
        }

        $utc = new DateTimeZone('UTC');

        return new self($start->setTimezone($utc), $end->setTimezone($utc));
    }

    public function contains(DateTimeImmutable $instant): bool
    {
        return $instant >= $this->start && $instant < $this->end;
    }

    public function equals(self $other): bool
    {
        // Compared as instants, to the microsecond. Two DateTimeImmutable objects
        // naming the same instant are not the same object.
        return $this->start->format('U.u') === $other->start->format('U.u')
            && $this->end->format('U.u') === $other->end->format('U.u');
    }
}
