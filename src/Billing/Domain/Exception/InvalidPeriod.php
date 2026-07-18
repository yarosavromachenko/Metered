<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use DateTimeImmutable;
use Metered\Shared\Domain\Exception\DomainException;

final class InvalidPeriod extends DomainException
{
    public static function notAfterStart(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self(sprintf(
            'A billing period must end after it starts: %s is not after %s.',
            $end->format(DATE_ATOM),
            $start->format(DATE_ATOM),
        ));
    }

    public static function beforeCycle(DateTimeImmutable $at, DateTimeImmutable $anchor): self
    {
        return new self(sprintf(
            '%s is before the billing cycle begins at %s.',
            $at->format(DATE_ATOM),
            $anchor->format(DATE_ATOM),
        ));
    }
}
