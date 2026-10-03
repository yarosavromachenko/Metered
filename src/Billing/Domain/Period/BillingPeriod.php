<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Period;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Billing\Domain\Exception\InvalidPeriod;
use Stringable;

/**
 * `[start, end)`: an event exactly on a boundary belongs to the later period.
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
        // Compare instants, not objects.
        return $this->start->format('U.u') === $other->start->format('U.u')
            && $this->end->format('U.u') === $other->end->format('U.u');
    }
}
