<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\Slug;

/**
 * @param  array<string, string>  $arguments
 * @return array{exitCode: int, output: string}
 */
function runOrgCreate(array $arguments): array
{
    $exitCode = Artisan::call('org:create', $arguments);

    return ['exitCode' => $exitCode, 'output' => Artisan::output()];
}

function tokenIn(string $output): string
{
    preg_match('/mk_(live|test)_[0-9a-f]{8}_[0-9a-f]{48}/', $output, $matches);

    return $matches[0] ?? '';
}

it('creates a usable tenant from one command', function (): void {
    $result = runOrgCreate(['name' => 'Acme, Inc.', '--environment' => 'live', '--currency' => 'USD']);

    expect($result['exitCode'])->toBe(0)
        ->and($result['output'])->toContain('Organization "Acme, Inc." is ready.')
        ->and($result['output'])->toContain('This is the only time the key is shown.');

    $organization = app(OrganizationRepository::class)->findBySlug(Slug::fromString('acme-inc'));

    expect($organization)->not->toBeNull()
        ->and(DB::table('projects')->where('organization_id', $organization?->id->value)->value('currency'))
        ->toBe('USD');
});

it('prints a token that authenticates, and stores only its hash', function (): void {
    $result = runOrgCreate(['name' => 'North Wind']);
    $token = tokenIn($result['output']);

    expect($token)->not->toBe('');

    $key = app(ApiKeyAuthenticator::class)->authenticate($token);

    expect($key->tenant->projectId->value)->toBe(DB::table('projects')->value('id'))
        // Printed, never stored: the row holds a prefix and a hash, and the
        // line above is the last time the secret exists anywhere.
        ->and(DB::table('api_keys')->where('prefix', $key->prefix)->value('secret_hash'))
        ->toBe(hash('sha256', $token));
});

it('refuses an environment that does not exist', function (): void {
    $result = runOrgCreate(['name' => 'Acme', '--environment' => 'staging']);

    expect($result['exitCode'])->toBe(2)
        ->and($result['output'])->toContain('must be "live" or "test"')
        ->and(DB::table('organizations')->count())->toBe(0);
});

it('refuses a currency the domain does not know', function (): void {
    $result = runOrgCreate(['name' => 'Acme', '--currency' => 'XYZ']);

    expect($result['exitCode'])->toBe(2)
        ->and($result['output'])->toContain('not a known ISO 4217 currency')
        ->and(DB::table('organizations')->count())->toBe(0);
});
