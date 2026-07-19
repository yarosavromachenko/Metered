<?php

declare(strict_types=1);

use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanCode;
use Metered\Shared\Domain\Exception\InvalidName;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

it('belongs to one project and is named for the panel', function (): void {
    $tenant = new TenantContext(
        Uuid::fromString('01924b7c-0000-7000-8000-0000000000f2'),
        Uuid::fromString('01924b7c-0000-7000-8000-0000000000f3'),
    );

    $plan = Plan::create(
        Uuid::fromString('01924b7c-0000-7000-8000-0000000000f1'),
        $tenant,
        PlanCode::fromString('pro'),
        '  Pro  ',
        new DateTimeImmutable('2026-09-23T10:00:00+00:00'),
    );

    expect($plan->id->value)->toBe('01924b7c-0000-7000-8000-0000000000f1')
        ->and($plan->tenant)->toBe($tenant)
        ->and((string) $plan->code)->toBe('pro')
        ->and($plan->name)->toBe('Pro')
        ->and($plan->createdAt->format(DATE_ATOM))->toBe('2026-09-23T10:00:00+00:00');
});

it('refuses a name that would not fit the column', function (): void {
    expect(static fn(): Plan => Plan::create(
        Uuid::fromString('01924b7c-0000-7000-8000-0000000000f1'),
        new TenantContext(
            Uuid::fromString('01924b7c-0000-7000-8000-0000000000f2'),
            Uuid::fromString('01924b7c-0000-7000-8000-0000000000f3'),
        ),
        PlanCode::fromString('pro'),
        str_repeat('n', 121),
        new DateTimeImmutable('2026-09-23T10:00:00+00:00'),
    ))->toThrow(InvalidName::class, 'at most 120');
});
