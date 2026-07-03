<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * An event that arrived and was not counted, kept with the reason.
 *
 * First-class rather than a log line, because validation here is asynchronous:
 * a client is told `202` before anything knows whether the meter exists
 * (ADR-0003), so this is the only place the answer can be found afterwards. A
 * tenant whose totals look short opens the rejections screen; without it they
 * open a support ticket.
 *
 * The payload is kept as it arrived, so that "what exactly did we send?" has
 * an answer. It is the tenant's own data, visible only inside their own
 * project.
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
     * The id the event claimed, when it claimed one this column can hold.
     *
     * Read leniently on purpose: this row exists because something about the
     * event was wrong, and the id is the one thing a tenant will search by.
     * A number sent unquoted is still an id; a nested structure is not.
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
