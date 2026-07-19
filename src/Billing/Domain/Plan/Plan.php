<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Plan;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;

/**
 * A tariff, as the catalog lists it. What it costs lives in its versions.
 *
 * The plan itself carries only what stays true across every version: its code
 * and its display name. Changing a price means publishing a new
 * {@see PlanVersion}, never editing the plan, so a subscription always knows
 * exactly which terms it was billed on.
 */
final readonly class Plan
{
    private const int NAME_LIMIT = 120;

    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public PlanCode $code,
        public string $name,
        public DateTimeImmutable $createdAt,
    ) {}

    public static function create(
        Uuid $id,
        TenantContext $tenant,
        PlanCode $code,
        string $name,
        DateTimeImmutable $at,
    ): self {
        return new self($id, $tenant, $code, Name::of($name, 'plan', self::NAME_LIMIT), $at);
    }
}
