<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Audit;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Who did what to what, recorded as the action ("invoice.voided"), not the
 * changed row. Each organization has its own chain (ADR-0020); a null
 * organization means the platform chain, so there is no default.
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ?Uuid $organizationId,
        public string $actor,
        public string $action,
        public string $subjectType,
        public ?string $subjectId,
        public array $payload,
        public DateTimeImmutable $occurredAt,
    ) {}
}
