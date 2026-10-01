<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Audit;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * One recorded act: who did what, to what, and when.
 *
 * Entries describe intent rather than the row that changed. "User X voided
 * invoice Y because Z" answers the question an incident actually raises;
 * "invoices.status changed to void" does not.
 *
 * Each organization's entries form a chain of their own (ADR-0020), so the
 * organization is part of every entry and has no default: one left out would
 * land in the platform chain without anyone noticing. Null is for an act that
 * belongs to no organization.
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
