<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;

/**
 * The definition of something measurable inside one project.
 *
 * A meter is two decisions: what the events are called ({@see MeterCode}) and
 * how they become one number ({@see Aggregation}). Everything else about
 * usage — the events, the aggregates, the invoice lines — is derived from
 * those two.
 *
 * Both are immutable after definition, and not by omission. Changing the code
 * would orphan every event already sent under it; changing the aggregation
 * would silently rewrite what past periods meant, including periods already
 * invoiced. A meter that needs either is a new meter.
 */
final readonly class Meter
{
    public const int NAME_LIMIT = 120;

    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public MeterCode $code,
        public string $name,
        public Aggregation $aggregation,
        public DateTimeImmutable $definedAt,
    ) {}

    public static function define(
        Uuid $id,
        TenantContext $tenant,
        MeterCode $code,
        string $name,
        Aggregation $aggregation,
        DateTimeImmutable $at,
    ): self {
        return new self($id, $tenant, $code, Name::of($name, 'meter', self::NAME_LIMIT), $aggregation, $at);
    }

    /**
     * Whether an event naming this code belongs to this meter. Asked by the
     * ingestion consumer, which holds a code and needs the meter behind it.
     */
    public function answersTo(MeterCode $code): bool
    {
        return $this->code->equals($code);
    }
}
