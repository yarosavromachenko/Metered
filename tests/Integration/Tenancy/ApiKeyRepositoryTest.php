<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Scope;
use Tests\Support\TenantFactory;

it('stores a key and finds it by the prefix a client presents', function (): void {
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite, Scope::Admin]);

    $found = app(ApiKeyRepository::class)->findByPrefix($secret->prefix());

    expect($found?->id->value)->toBe($key->id->value)
        ->and($found?->matches($secret))->toBeTrue()
        ->and($found?->allows(Scope::Admin))->toBeTrue()
        ->and($found?->tenant->projectId->value)->toBe($project->id->value);
});

it('stores no secret anywhere in the row', function (): void {
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project);

    $row = DB::table('api_keys')->where('prefix', $secret->prefix())->first();
    $serialised = json_encode((array) $row);

    expect($serialised)->not->toContain(substr($secret->reveal(), -48))
        ->and($serialised)->not->toContain($secret->reveal());
});

it('will not hand a key to the wrong tenant', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');
    ['key' => $key] = TenantFactory::apiKey($acme);

    $keys = app(ApiKeyRepository::class);

    expect($keys->find($acme->tenant(), $key->id)?->id->value)->toBe($key->id->value)
        ->and($keys->find($rival->tenant(), $key->id))->toBeNull()
        // The rival's own organization paired with Acme's project: a scope
        // built from two halves that do not belong together.
        ->and($keys->find(new TenantContext($rival->organizationId, $acme->id), $key->id))->toBeNull();
});

it('lists the keys of one project, newest first', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');

    // The instants are stated rather than read from the clock twice in a row:
    // the order under test is the one they name, not the one the machine's
    // clock happened to produce between two inserts.
    TenantFactory::apiKey($acme, name: 'First', issuedAt: new DateTimeImmutable('2026-09-14T09:00:00+00:00'));
    TenantFactory::apiKey($acme, name: 'Second', issuedAt: new DateTimeImmutable('2026-09-14T09:00:01+00:00'));
    TenantFactory::apiKey($rival, name: 'Theirs', issuedAt: new DateTimeImmutable('2026-09-14T09:00:02+00:00'));

    $names = array_map(
        static fn(ApiKey $key): string => $key->name,
        app(ApiKeyRepository::class)->listFor($acme->tenant()),
    );

    expect($names)->toBe(['Second', 'First']);
});

it('persists a revocation and leaves it where it was on a second attempt', function (): void {
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);
    $keys = app(ApiKeyRepository::class);

    $revokedAt = new DateTimeImmutable('2026-09-14T09:00:00+00:00');
    $keys->save($key->revoke($revokedAt));

    $stored = $keys->findByPrefix($secret->prefix());
    $keys->save($stored?->revoke(new DateTimeImmutable('2026-09-15T09:00:00+00:00')) ?? $key);

    expect($keys->findByPrefix($secret->prefix())?->revokedAt?->format(DATE_RFC3339))
        ->toBe('2026-09-14T09:00:00+00:00');
});

it('records the moment a key was last used', function (): void {
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);

    app(ApiKeyRepository::class)->save($key->usedAt(new DateTimeImmutable('2026-09-14T09:00:00+00:00')));

    expect(app(ApiKeyRepository::class)->findByPrefix($secret->prefix())?->lastUsedAt?->format(DATE_RFC3339))
        ->toBe('2026-09-14T09:00:00+00:00');
});

it('refuses a second key with the same prefix', function (): void {
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);

    $duplicate = ApiKey::issue(
        app(IdentifierGenerator::class)->generate(),
        $project->tenant(),
        'Duplicate',
        $secret,
        [Scope::Admin],
        now()->toDateTimeImmutable(),
    );

    expect(static function () use ($duplicate): void {
        app(ApiKeyRepository::class)->save($duplicate);
    })->toThrow(QueryException::class, 'api_keys_prefix_unique');

    expect($key->prefix)->toBe($secret->prefix());
});

it('lets the database refuse a key whose environment is not its project\'s', function (): void {
    $project = TenantFactory::tenant();
    $secret = ApiKeySecret::generate(Environment::Live);

    expect(static fn(): bool => DB::table('api_keys')->insert(row($project->tenant(), $secret, [
        // The project above is a test project; this row claims live.
        'environment' => Environment::Live->value,
    ])))->toThrow(QueryException::class, 'api_keys_project_id_organization_id_environment_foreign');
});

it('lets the database refuse a key that belongs to nobody', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');
    $secret = ApiKeySecret::generate($acme->environment);

    expect(static fn(): bool => DB::table('api_keys')->insert(row($acme->tenant(), $secret, [
        // Acme's project, the rival's organization.
        'organization_id' => $rival->organizationId->value,
    ])))->toThrow(QueryException::class, 'api_keys_project_id_organization_id_environment_foreign');
});

it('lets the database refuse a key with no scopes at all', function (): void {
    $project = TenantFactory::tenant();
    $secret = ApiKeySecret::generate($project->environment);

    expect(static fn(): bool => DB::table('api_keys')->insert(row($project->tenant(), $secret, [
        'scopes' => '[]',
    ])))->toThrow(QueryException::class, 'api_keys_scopes_check');
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function row(TenantContext $tenant, ApiKeySecret $secret, array $overrides = []): array
{
    return array_merge([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
        'name' => 'Raw insert',
        'prefix' => $secret->prefix(),
        'secret_hash' => $secret->hash(),
        'environment' => Environment::Test->value,
        'scopes' => '["usage:write"]',
        'created_at' => now(),
    ], $overrides);
}
