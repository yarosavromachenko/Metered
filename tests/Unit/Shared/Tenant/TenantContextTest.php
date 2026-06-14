<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

function tenant(string $organization, string $project): TenantContext
{
    return new TenantContext(Uuid::fromString($organization), Uuid::fromString($project));
}

const ORGANIZATION_A = '01924b7c-0000-7000-8000-0000000000a1';
const ORGANIZATION_B = '01924b7c-0000-7000-8000-0000000000b1';
const PROJECT_A = '01924b7c-0000-7000-8000-0000000000a2';
const PROJECT_B = '01924b7c-0000-7000-8000-0000000000b2';

it('carries the organization and the project together', function (): void {
    $context = tenant(ORGANIZATION_A, PROJECT_A);

    expect($context->organizationId->value)->toBe(ORGANIZATION_A)
        ->and($context->projectId->value)->toBe(PROJECT_A);
});

it('is equal only to the same pair', function (): void {
    $context = tenant(ORGANIZATION_A, PROJECT_A);

    expect($context->equals(tenant(ORGANIZATION_A, PROJECT_A)))->toBeTrue()
        // Same organization, different project: a `live` key must not reach
        // `test` data, so this pair is a different tenant context.
        ->and($context->equals(tenant(ORGANIZATION_A, PROJECT_B)))->toBeFalse()
        ->and($context->equals(tenant(ORGANIZATION_B, PROJECT_A)))->toBeFalse();
});

it('describes itself for a log line without inventing a format', function (): void {
    expect((string) tenant(ORGANIZATION_A, PROJECT_A))
        ->toBe(ORGANIZATION_A . '/' . PROJECT_A);
});
