<?php

declare(strict_types=1);

use Metered\Shared\Domain\Exception\InvalidMoney;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Slug;

const ORGANIZATION = '01924b7c-0000-7000-8000-00000000000a';
const PROJECT = '01924b7c-0000-7000-8000-00000000000b';

it('belongs to an organization and declares an environment and a currency', function (): void {
    $project = project('EUR', Environment::Live);

    expect($project->organizationId->value)->toBe(ORGANIZATION)
        ->and($project->environment)->toBe(Environment::Live)
        ->and($project->currency)->toBe('EUR');
});

it('normalises the currency code', function (string $given): void {
    expect(project($given)->currency)->toBe('EUR');
})->with(['eur', 'EUR', ' eur ']);

it('refuses a currency that does not exist', function (): void {
    expect(static fn(): Project => project('XYZ'))
        ->toThrow(InvalidMoney::class, 'not a known ISO 4217 currency');
});

it('hands out the tenant context that scopes every query beneath it', function (): void {
    $context = project()->tenant();

    expect($context->organizationId->value)->toBe(ORGANIZATION)
        ->and($context->projectId->value)->toBe(PROJECT);
});

it('knows whether it is the environment that bills real customers', function (): void {
    expect(Environment::Live->isLive())->toBeTrue()
        ->and(Environment::Test->isLive())->toBeFalse()
        ->and(Environment::from('test'))->toBe(Environment::Test);
});

function project(string $currency = 'EUR', Environment $environment = Environment::Test): Project
{
    return Project::open(
        Uuid::fromString(PROJECT),
        Uuid::fromString(ORGANIZATION),
        'Production',
        Slug::fromString('production'),
        $environment,
        $currency,
        new DateTimeImmutable('2026-09-13T12:00:00+00:00'),
    );
}
