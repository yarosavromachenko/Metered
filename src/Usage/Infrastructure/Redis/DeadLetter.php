<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

/**
 * One entry of the dead-letter stream, as an operator reads it: why it is
 * there, how often it was tried, and enough of the event to recognise it.
 *
 * Every field but the id and the reason can be missing — a message is
 * dead-lettered precisely when it could not be read — so they are nullable
 * rather than guessed.
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
