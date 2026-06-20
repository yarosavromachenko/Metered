<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Authentication\AuthenticationFailed;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\Scope;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\TenantFactory;

/** The window docs/api.md promises, and the one config ships with. */
const REVOCATION_WINDOW_SECONDS = 30;

const USAGE_INTERVAL_SECONDS = 300;

/**
 * A clock the test drives, bound into the container so that fixtures are
 * created on the same one the authenticator reads.
 */
function mockClock(): MockClock
{
    $clock = new MockClock('2026-09-15 09:00:00', 'UTC');
    app()->instance(ClockInterface::class, $clock);

    return $clock;
}

/**
 * Moves both clocks the system has: the injected one the domain reads, and
 * Carbon's, which is what the cache computes expiry against. Laravel resets
 * Carbon's test value after each test.
 */
function advance(MockClock $clock, int $seconds): void
{
    $clock->sleep($seconds);
    Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
}

function authenticator(MockClock $clock): ApiKeyAuthenticator
{
    return new ApiKeyAuthenticator(app(ApiKeyRepository::class), $clock, USAGE_INTERVAL_SECONDS);
}

it('authenticates a token and answers with the tenant it belongs to', function (): void {
    $clock = mockClock();
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    $key = authenticator($clock)->authenticate($secret->reveal());

    expect($key->tenant->projectId->value)->toBe($project->id->value)
        ->and($key->tenant->organizationId->value)->toBe($project->organizationId->value)
        ->and($key->allows(Scope::UsageWrite))->toBeTrue();
});

it('refuses anything that is not one of our tokens', function (string $token): void {
    $clock = mockClock();

    expect(static fn(): mixed => authenticator($clock)->authenticate($token))
        ->toThrow(AuthenticationFailed::class, 'expected format');
})->with(['', 'Bearer', 'mk_live_nope', 'sk_live_7f3a1b2c_' . 'a']);

it('refuses a token whose prefix nobody has', function (): void {
    $clock = mockClock();
    $token = 'mk_test_00000000_' . str_repeat('a', 48);

    expect(static fn(): mixed => authenticator($clock)->authenticate($token))
        ->toThrow(AuthenticationFailed::class, 'not valid');
});

it('refuses the right prefix with the wrong secret', function (): void {
    $clock = mockClock();
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project);

    // Same prefix, different secret: the shape of a guess, or of a token
    // truncated in a configuration file.
    $forged = sprintf('mk_%s_%s_%s', $project->environment->value, $secret->prefix(), str_repeat('b', 48));

    expect(static fn(): mixed => authenticator($clock)->authenticate($forged))
        ->toThrow(AuthenticationFailed::class, 'not valid');
});

it('refuses a key that was revoked', function (): void {
    $clock = mockClock();
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);

    app(ApiKeyRepository::class)->save($key->revoke($clock->now()));

    expect(static fn(): mixed => authenticator($clock)->authenticate($secret->reveal()))
        ->toThrow(AuthenticationFailed::class, 'was revoked');
});

it('stops accepting a key revoked elsewhere within the documented window', function (): void {
    $clock = mockClock();
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project);
    $authenticator = authenticator($clock);

    // Twice: the first call records last use, which drops the cache entry it
    // had just filled. The second leaves the key cached, which is the state
    // this test is about.
    $authenticator->authenticate($secret->reveal());
    $authenticator->authenticate($secret->reveal());

    // Revoked straight in the database, as another node, a console command or
    // a migration would — nothing here invalidates the cache.
    DB::table('api_keys')
        ->where('prefix', $secret->prefix())
        ->update(['revoked_at' => $clock->now()]);

    advance($clock, REVOCATION_WINDOW_SECONDS - 1);

    expect($authenticator->authenticate($secret->reveal())->prefix)
        ->toBe($secret->prefix(), 'a cached key may lag, but only inside the window');

    advance($clock, 2);

    expect(static fn(): mixed => $authenticator->authenticate($secret->reveal()))
        ->toThrow(AuthenticationFailed::class, 'was revoked');
});

it('records last use once per interval rather than once per request', function (): void {
    $clock = mockClock();
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project);
    $authenticator = authenticator($clock);

    $authenticator->authenticate($secret->reveal());
    $firstUse = $clock->now();

    advance($clock, USAGE_INTERVAL_SECONDS - 1);
    $authenticator->authenticate($secret->reveal());

    expect(lastUsedAt($secret->prefix()))->toBe($firstUse->format('Y-m-d H:i:s'));

    advance($clock, 2);
    $authenticator->authenticate($secret->reveal());

    expect(lastUsedAt($secret->prefix()))->toBe($clock->now()->format('Y-m-d H:i:s'));
});

function lastUsedAt(string $prefix): string
{
    $value = DB::table('api_keys')->where('prefix', $prefix)->value('last_used_at');

    return (new DateTimeImmutable(is_string($value) ? $value : '@0'))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}
