<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Webhooks\Domain\Endpoint\EventType;
use Metered\Webhooks\Domain\Exception\DeliveryRefused;

/**
 * One event on its way to one endpoint.
 *
 * The body is fixed when the delivery is created — every attempt, and every
 * replay, sends the same bytes, so a receiver deduplicating on the event id
 * sees the same event each time. Only the signature changes: it carries the
 * time of the attempt.
 */
final readonly class Delivery
{
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public Uuid $endpointId,
        public Uuid $eventId,
        public EventType $eventType,
        public string $body,
        public DeliveryStatus $status,
        public int $attempts,
        public ?DateTimeImmutable $nextAttemptAt,
        public ?int $lastStatusCode,
        public DateTimeImmutable $createdAt,
    ) {}

    public static function schedule(
        Uuid $id,
        TenantContext $tenant,
        Uuid $endpointId,
        Uuid $eventId,
        EventType $eventType,
        string $body,
        DateTimeImmutable $at,
    ): self {
        return new self($id, $tenant, $endpointId, $eventId, $eventType, $body, DeliveryStatus::Pending, 0, $at, null, $at);
    }

    /**
     * @internal for the repository, rebuilding a delivery exactly as it was stored
     */
    public static function restore(
        Uuid $id,
        TenantContext $tenant,
        Uuid $endpointId,
        Uuid $eventId,
        EventType $eventType,
        string $body,
        DeliveryStatus $status,
        int $attempts,
        ?DateTimeImmutable $nextAttemptAt,
        ?int $lastStatusCode,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $tenant, $endpointId, $eventId, $eventType, $body, $status, $attempts, $nextAttemptAt, $lastStatusCode, $createdAt);
    }

    public function isDueAt(DateTimeImmutable $now): bool
    {
        return $this->status === DeliveryStatus::Pending && $this->nextAttemptAt <= $now;
    }

    /**
     * @param int $jitter a draw for the retry schedule, see RetrySchedule
     */
    public function attempted(AttemptResult $result, DateTimeImmutable $at, int $jitter): self
    {
        $attempts = $this->attempts + 1;

        return match ($result->verdict()) {
            Verdict::Delivered => $this->with(DeliveryStatus::Succeeded, $attempts, null, $result->statusCode),
            Verdict::GiveUp => $this->with(DeliveryStatus::Failed, $attempts, null, $result->statusCode),
            Verdict::Retry => ($next = RetrySchedule::nextAttemptAt($attempts, $at, $jitter)) instanceof DateTimeImmutable
                ? $this->with(DeliveryStatus::Pending, $attempts, $next, $result->statusCode)
                : $this->with(DeliveryStatus::Dead, $attempts, null, $result->statusCode),
        };
    }

    /**
     * Not attempted — the endpoint's breaker is open — and asked again when it
     * may be. Waiting is not a failure, so no attempt is counted.
     */
    public function postponedUntil(DateTimeImmutable $at): self
    {
        return $this->with(DeliveryStatus::Pending, $this->attempts, $at, $this->lastStatusCode);
    }

    /**
     * Starts a dead or failed delivery again from its first attempt, with the
     * same body.
     */
    public function replay(DateTimeImmutable $now): self
    {
        if ($this->status !== DeliveryStatus::Dead && $this->status !== DeliveryStatus::Failed) {
            throw DeliveryRefused::notReplayable($this->status->value);
        }

        return $this->with(DeliveryStatus::Pending, 0, $now, $this->lastStatusCode);
    }

    private function with(DeliveryStatus $status, int $attempts, ?DateTimeImmutable $next, ?int $lastStatusCode): self
    {
        return new self(
            $this->id,
            $this->tenant,
            $this->endpointId,
            $this->eventId,
            $this->eventType,
            $this->body,
            $status,
            $attempts,
            $next,
            $lastStatusCode,
            $this->createdAt,
        );
    }
}
