<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Plan;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;

/**
 * Code and name only; prices live in {@see PlanVersion}, and a price change is
 * a new version.
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
