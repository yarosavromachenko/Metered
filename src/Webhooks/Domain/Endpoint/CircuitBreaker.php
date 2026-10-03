<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Endpoint;

use DateTimeImmutable;

/**
 * Opens after `threshold` consecutive failures; after the cooldown one probe
 * goes out (half-open), which closes or reopens it. A probe that never
 * reports is replaced after another cooldown.
 */
final readonly class CircuitBreaker
{
    private function __construct(
        public BreakerState $state,
        public int $consecutiveFailures,
        public ?DateTimeImmutable $changedAt,
    ) {}

    public static function closed(): self
    {
        return new self(BreakerState::Closed, 0, null);
    }

    /**
     * @internal for the repository
     */
    public static function restore(BreakerState $state, int $consecutiveFailures, ?DateTimeImmutable $changedAt): self
    {
        return new self($state, $consecutiveFailures, $changedAt);
    }

    /**
     * The new state if a delivery may go now, null if it must wait.
     */
    public function admit(DateTimeImmutable $now, int $cooldownSeconds): ?self
    {
        if ($this->state === BreakerState::Closed) {
            return $this;
        }

        if ($now < $this->reopensAt($cooldownSeconds)) {
            return null;
        }

        return new self(BreakerState::HalfOpen, $this->consecutiveFailures, $now);
    }

    /**
     * When a waiting delivery should be tried again; null when closed.
     */
    public function reopensAt(int $cooldownSeconds): ?DateTimeImmutable
    {
        if ($this->state === BreakerState::Closed) {
            return null;
        }

        return $this->changedAt?->modify(sprintf('+%d seconds', $cooldownSeconds));
    }

    public function succeeded(): self
    {
        return self::closed();
    }

    public function failed(DateTimeImmutable $now, int $threshold): self
    {
        $failures = $this->consecutiveFailures + 1;

        if ($this->state === BreakerState::HalfOpen || $failures >= $threshold) {
            return new self(BreakerState::Open, $failures, $now);
        }

        return new self(BreakerState::Closed, $failures, $this->changedAt);
    }
}
