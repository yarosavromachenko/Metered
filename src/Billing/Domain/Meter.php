<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;

/**
 * A {@see MeterCode} and an {@see Aggregation}, both immutable: changing them
 * would orphan sent events or change invoiced periods. Create a new meter
 * instead.
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

    public function answersTo(MeterCode $code): bool
    {
        return $this->code->equals($code);
    }
}
