<?php

declare(strict_types=1);

use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Shared\Domain\Exception\InvalidName;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;

const METER_ID = '01924b7c-0000-7000-8000-0000000000d1';
const METER_ORG_ID = '01924b7c-0000-7000-8000-0000000000d2';
const METER_PROJECT_ID = '01924b7c-0000-7000-8000-0000000000d3';
const DEFINED_AT = '2026-09-22T09:00:00+00:00';

it('belongs to one project and says how its events are folded', function (): void {
    $meter = defineMeter('api.requests', Aggregation::Count);

    expect($meter->id->value)->toBe(METER_ID)
        ->and($meter->tenant->projectId->value)->toBe(METER_PROJECT_ID)
        ->and($meter->tenant->organizationId->value)->toBe(METER_ORG_ID)
        ->and((string) $meter->code)->toBe('api.requests')
        ->and($meter->aggregation)->toBe(Aggregation::Count)
        ->and($meter->name)->toBe('Api.requests')
        ->and($meter->definedAt->format(DATE_ATOM))->toBe(DEFINED_AT);
});

it('is named for the people reading the panel, not for the machines sending events', function (): void {
    $meter = defineMeter('s3.storage_gb', Aggregation::Max, 'Stored gigabytes, peak');

    expect($meter->name)->toBe('Stored gigabytes, peak')
        ->and((string) $meter->code)->toBe('s3.storage_gb');
});

it('refuses a name that would not fit the column', function (): void {
    expect(static fn(): Meter => defineMeter('api.requests', Aggregation::Sum, str_repeat('n', 121)))
        ->toThrow(InvalidName::class, 'at most 120');
});

it('answers to the same code the events carry', function (): void {
    $meter = defineMeter('API.Requests', Aggregation::Sum);

    expect($meter->answersTo(MeterCode::fromString('api.requests')))->toBeTrue()
        ->and($meter->answersTo(MeterCode::fromString('api.responses')))->toBeFalse();
});

function defineMeter(
    string $code,
    Aggregation $aggregation,
    ?string $name = null,
): Meter {
    return Meter::define(
        Uuid::fromString(METER_ID),
        new TenantContext(Uuid::fromString(METER_ORG_ID), Uuid::fromString(METER_PROJECT_ID)),
        MeterCode::fromString($code),
        $name ?? ucfirst(strtolower($code)),
        $aggregation,
        new DateTimeImmutable(DEFINED_AT),
    );
}
