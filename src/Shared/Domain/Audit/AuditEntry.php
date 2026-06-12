<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Audit;

use DateTimeImmutable;

/**
 * One recorded act: who did what, to what, and when.
 *
 * Entries describe intent rather than the row that changed. "User X voided
 * invoice Y because Z" answers the question an incident actually raises;
 * "invoices.status changed to void" does not.
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $actor,
        public string $action,
        public string $subjectType,
        public ?string $subjectId,
        public array $payload,
        public DateTimeImmutable $occurredAt,
    ) {}
}
