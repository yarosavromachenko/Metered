<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Invoicing\Domain\Exception\InvalidInvoice;
use Stringable;

/**
 * `[start, end)`, copied from Billing. A cancelled final period ends at the
 * cancellation. An hourly bucket belongs to the period its start falls in
 * (assumption 24).
 */
final readonly class InvoicePeriod implements Stringable
{
    private function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}

    public function __toString(): string
    {
        return sprintf('%s – %s', $this->start->format('Y-m-d'), $this->end->format('Y-m-d'));
    }

    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        if ($end <= $start) {
            throw InvalidInvoice::periodNotAfterStart($start, $end);
        }

        $utc = new DateTimeZone('UTC');

        return new self($start->setTimezone($utc), $end->setTimezone($utc));
    }

    public function contains(DateTimeImmutable $instant): bool
    {
        return $instant >= $this->start && $instant < $this->end;
    }

    /**
     * End plus the grace window (ADR-0010).
     */
    public function closesAt(int $graceSeconds): DateTimeImmutable
    {
        if ($graceSeconds < 0) {
            throw InvalidInvoice::negativeGrace($graceSeconds);
        }

        return $this->end->modify(sprintf('+%d seconds', $graceSeconds));
    }

    public function isClosableAt(DateTimeImmutable $now, int $graceSeconds): bool
    {
        return $now >= $this->closesAt($graceSeconds);
    }

    public function equals(self $other): bool
    {
        return $this->start->format('U.u') === $other->start->format('U.u')
            && $this->end->format('U.u') === $other->end->format('U.u');
    }
}
