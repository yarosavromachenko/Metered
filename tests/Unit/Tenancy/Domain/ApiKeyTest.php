<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Exception\InvalidApiKey;
use Metered\Tenancy\Domain\Scope;

const KEY_ID = '01924b7c-0000-7000-8000-0000000000c1';
const ORG_ID = '01924b7c-0000-7000-8000-0000000000c2';
const PROJECT_ID = '01924b7c-0000-7000-8000-0000000000c3';

const ISSUED_AT = '2026-09-13T12:00:00+00:00';

it('is issued for one project and remembers only the hash', function (): void {
    $secret = ApiKeySecret::generate(Environment::Test);
    $key = issue($secret);

    expect($key->tenant->projectId->value)->toBe(PROJECT_ID)
        ->and($key->tenant->organizationId->value)->toBe(ORG_ID)
        ->and($key->prefix)->toBe($secret->prefix())
        ->and($key->environment)->toBe(Environment::Test)
        ->and($key->secretHash)->toBe($secret->hash())
        ->and($key->lastUsedAt)->toBeNull()
        ->and($key->revokedAt)->toBeNull();
});

it('recognises the secret it was issued from and nothing else', function (): void {
    $secret = ApiKeySecret::generate(Environment::Test);
    $key = issue($secret);

    expect($key->matches($secret))->toBeTrue()
        ->and($key->matches(ApiKeySecret::generate(Environment::Test)))->toBeFalse()
        // Same secret, other environment: the token's shape differs, so the
        // hash differs, and a test key can never authenticate against live.
        ->and($key->matches(ApiKeySecret::generate(Environment::Live)))->toBeFalse();
});

it('carries the scopes it was granted and no others', function (): void {
    $key = issue(scopes: [Scope::UsageWrite]);

    expect($key->allows(Scope::UsageWrite))->toBeTrue()
        ->and($key->allows(Scope::Admin))->toBeFalse();
});

it('stores a scope once however often it was asked for', function (): void {
    // The duplicate comes first on purpose: deduplicating leaves a hole at
    // index 1, and an array with a hole encodes as a JSON object rather than
    // an array — which the scopes column's CHECK constraint then refuses.
    $key = issue(scopes: [Scope::UsageWrite, Scope::UsageWrite, Scope::Admin]);

    expect($key->scopes)->toBe([Scope::UsageWrite, Scope::Admin])
        ->and(array_is_list($key->scopes))->toBeTrue();
});

it('refuses to exist without a scope, because it could do nothing', function (): void {
    expect(static fn(): ApiKey => issue(scopes: []))
        ->toThrow(InvalidApiKey::class, 'at least one scope');
});

it('is revoked from the moment it was revoked', function (): void {
    $key = issue()->revoke(new DateTimeImmutable('2026-09-14T09:00:00+00:00'));

    expect($key->isRevokedAt(new DateTimeImmutable('2026-09-14T08:59:59+00:00')))->toBeFalse()
        ->and($key->isRevokedAt(new DateTimeImmutable('2026-09-14T09:00:00+00:00')))->toBeTrue()
        ->and($key->isRevokedAt(new DateTimeImmutable('2026-09-14T09:00:01+00:00')))->toBeTrue();
});

it('keeps the first revocation when it is revoked twice', function (): void {
    $key = issue()
        ->revoke(new DateTimeImmutable('2026-09-14T09:00:00+00:00'))
        ->revoke(new DateTimeImmutable('2026-09-15T09:00:00+00:00'));

    expect($key->revokedAt?->format(DATE_RFC3339))->toBe('2026-09-14T09:00:00+00:00');
});

it('comes back from storage as the key it was', function (): void {
    $secret = ApiKeySecret::generate(Environment::Live);
    $stored = ApiKey::fromStorage(
        Uuid::fromString(KEY_ID),
        new TenantContext(Uuid::fromString(ORG_ID), Uuid::fromString(PROJECT_ID)),
        'Ingestion',
        $secret->prefix(),
        $secret->hash(),
        Environment::Live,
        [Scope::UsageWrite],
        new DateTimeImmutable(ISSUED_AT),
        new DateTimeImmutable('2026-09-14T09:00:00+00:00'),
        null,
    );

    expect($stored->matches($secret))->toBeTrue()
        ->and($stored->isRevokedAt(new DateTimeImmutable('2026-09-14T09:00:00+00:00')))->toBeTrue()
        ->and($stored->lastUsedAt)->toBeNull();
});

/**
 * @param  list<Scope>  $scopes
 */
function issue(?ApiKeySecret $secret = null, array $scopes = [Scope::UsageWrite, Scope::Admin]): ApiKey
{
    return ApiKey::issue(
        Uuid::fromString(KEY_ID),
        new TenantContext(Uuid::fromString(ORG_ID), Uuid::fromString(PROJECT_ID)),
        'Ingestion',
        $secret ?? ApiKeySecret::generate(Environment::Test),
        $scopes,
        new DateTimeImmutable(ISSUED_AT),
    );
}
