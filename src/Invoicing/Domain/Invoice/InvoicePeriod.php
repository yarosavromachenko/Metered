<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Invoicing\Domain\Exception\InvalidInvoice;
use Stringable;

/**
 * The stretch of time an invoice, or one of its lines, bills: `[start, end)`.
 *
 * Billing owns how periods follow from an anchor; this is the copy an invoice
 * keeps of the one it was built for. A final period cut short by a
 * cancellation ends at the cancellation, so its end need not be a cycle
 * boundary.
 *
 * Usage belongs to a period by the hourly bucket it was aggregated into: a
 * bucket whose start falls inside the period is billed on it. Aggregates are
 * the only thing the invoice reads, and they are hourly (assumptions, 24).
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
     * When the period may be invoiced: one grace window after it ends, so that
     * events still in flight at the boundary land on this invoice rather than
     * arriving late (ADR-0010).
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
