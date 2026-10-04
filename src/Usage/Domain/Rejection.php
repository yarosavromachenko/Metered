<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Stored because validation happens after the 202 (ADR-0003); the tenant sees
 * rejections in the panel and the API. The payload is kept as received.
 */
final readonly class Rejection
{
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public ?string $eventId,
        public RejectionReason $reason,
        public string $detail,
        /** @var array<string, mixed> */
        public array $payload,
        public DateTimeImmutable $rejectedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function of(
        Uuid $id,
        TenantContext $tenant,
        RejectionReason $reason,
        string $detail,
        array $payload,
        DateTimeImmutable $at,
    ): self {
        return new self(
            $id,
            $tenant,
            self::eventIdIn($payload),
            $reason,
            $detail,
            $payload,
            $at->setTimezone(new DateTimeZone('UTC')),
        );
    }

    /**
     * Lenient: an unquoted number counts as an id, a nested value does not.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function eventIdIn(array $payload): ?string
    {
        $raw = $payload['event_id'] ?? null;

        if (is_int($raw)) {
            $raw = (string) $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' || strlen($trimmed) > EventId::MAX_LENGTH ? null : $trimmed;
    }
}
