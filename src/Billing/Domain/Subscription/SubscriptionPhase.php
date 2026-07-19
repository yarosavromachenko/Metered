<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Subscription;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * A stretch of a subscription's life pinned to one plan version, `[startsAt,
 * endsAt)`. The last phase of a running subscription has no end yet.
 */
final readonly class SubscriptionPhase
{
    public function __construct(
        public Uuid $planVersionId,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
    ) {}

    public function covers(DateTimeImmutable $instant): bool
    {
        return $instant >= $this->startsAt && (!$this->endsAt instanceof DateTimeImmutable || $instant < $this->endsAt);
    }

    public function endingAt(DateTimeImmutable $end): self
    {
        return new self($this->planVersionId, $this->startsAt, $end);
    }
}
