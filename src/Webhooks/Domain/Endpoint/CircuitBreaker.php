<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Endpoint;

use DateTimeImmutable;

/**
 * Whether an endpoint is taking deliveries.
 *
 * Closed until `threshold` deliveries fail in a row; then open, and nothing is
 * attempted until the cooldown passes. Then half-open: exactly one delivery
 * goes out as a probe. Its success closes the breaker, its failure opens it
 * again. A probe that never reports — a worker killed mid-request — is
 * replaced after another cooldown, so a breaker cannot stay half-open forever.
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
     * @internal for the repository, rebuilding a breaker exactly as it was stored
     */
    public static function restore(BreakerState $state, int $consecutiveFailures, ?DateTimeImmutable $changedAt): self
    {
        return new self($state, $consecutiveFailures, $changedAt);
    }

    /**
     * The breaker to store if a delivery may go out at $now, or null if it
     * must wait. An open breaker past its cooldown lets this one through as
     * the probe, and is half-open from then on.
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
