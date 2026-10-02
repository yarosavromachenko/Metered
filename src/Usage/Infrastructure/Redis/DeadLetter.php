<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

/**
 * All fields but id and reason are nullable: the message may be unreadable.
 */
final readonly class DeadLetter
{
    public function __construct(
        public string $id,
        public string $reason,
        public ?int $deliveries,
        public ?string $deadLetteredAt,
        public ?string $projectId,
        public ?string $eventId,
        public ?string $meterCode,
        public ?string $customerReference,
    ) {}

    /**
     * @param  array<string, string>  $fields
     */
    public static function fromEntry(string $id, array $fields): self
    {
        $deliveries = $fields[DeadLetters::DELIVERIES] ?? null;

        return new self(
            $id,
            $fields[DeadLetters::REASON] ?? 'unknown',
            $deliveries !== null && ctype_digit($deliveries) ? (int) $deliveries : null,
            $fields[DeadLetters::DEAD_LETTERED_AT] ?? null,
            $fields['project_id'] ?? null,
            $fields['event_id'] ?? null,
            $fields['meter_code'] ?? null,
            $fields['customer_ref'] ?? null,
        );
    }
}
