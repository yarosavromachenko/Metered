<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The UTC hour an aggregate is keyed by; period boundaries fall on whole hours.
 */
final readonly class Bucket
{
    private function __construct(public DateTimeImmutable $start) {}

    public static function containing(DateTimeImmutable $instant): self
    {
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));

        // Also clears microseconds, which would split one bucket into two.
        return new self($utc->setTime((int) $utc->format('G'), 0));
    }

    public function equals(self $other): bool
    {
        return $this->start->getTimestamp() === $other->start->getTimestamp();
    }
}
