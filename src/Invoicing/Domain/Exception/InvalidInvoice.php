<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Exception;

use DateTimeImmutable;
use Metered\Shared\Domain\Exception\DomainException;

final class InvalidInvoice extends DomainException
{
    public static function periodNotAfterStart(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self(sprintf(
            'An invoice period must end after it starts: %s is not after %s.',
            $end->format(DATE_ATOM),
            $start->format(DATE_ATOM),
        ));
    }

    public static function negativeGrace(int $seconds): self
    {
        return new self(sprintf('A grace window cannot be negative, %d seconds given.', $seconds));
    }

    public static function negativeLine(string $amount): self
    {
        return new self(sprintf('An invoice line cannot charge a negative amount, %s given.', $amount));
    }

    public static function lineCurrency(string $invoice, string $line): self
    {
        return new self(sprintf('An invoice in %s cannot carry a line in %s.', $invoice, $line));
    }

    public static function lateLineForItsOwnPeriod(): self
    {
        return new self('A late line bills an earlier period; it cannot cover the invoice\'s own.');
    }

    public static function numberNotPositive(int $sequence): self
    {
        return new self(sprintf('A document number starts at 1, %d given.', $sequence));
    }
}
