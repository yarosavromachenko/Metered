<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Idempotency\IdempotencyStore;
use Metered\Shared\Domain\Idempotency\ClaimStatus;
use Metered\Shared\Domain\Idempotency\StoredResponse;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * A key is remembered for 24 hours and no longer (ADR-0006): within the
 * window a retry replays, after it the same key is a new request, and the
 * hourly purge removes what has expired.
 */
function retentionClock(): MockClock
{
    $clock = new MockClock('2026-09-30 12:00:00', 'UTC');
    app()->instance(ClockInterface::class, $clock);
    app()->forgetInstance(IdempotencyStore::class);

    return $clock;
}

function requestFingerprint(string $body = '{"name":"acme"}'): string
{
    return hash('sha256', $body);
}

function completedKey(string $key): void
{
    $store = app(IdempotencyStore::class);
    $store->claim('scope-a', $key, requestFingerprint());
    $store->complete('scope-a', $key, new StoredResponse(201, [], '{"id":1}'));
}

it('replays a completed key within its window', function (): void {
    $clock = retentionClock();
    completedKey('key-1');

    $clock->modify('+23 hours');

    expect(app(IdempotencyStore::class)->claim('scope-a', 'key-1', requestFingerprint())->status)->toBe(ClaimStatus::Replayed);
});

it('treats a key past its window as a new request', function (): void {
    $clock = retentionClock();
    completedKey('key-1');

    $clock->modify('+25 hours');

    // A different fingerprint too: an expired record is gone, not a
    // conflict to report.
    expect(app(IdempotencyStore::class)->claim('scope-a', 'key-1', requestFingerprint('{"name":"other"}'))->status)->toBe(ClaimStatus::Claimed);
});

it('purges expired records and keeps the ones still in their window', function (): void {
    $clock = retentionClock();
    completedKey('old');
    $clock->modify('+12 hours');
    completedKey('recent');
    $clock->modify('+13 hours');

    expect(Artisan::call('idempotency:purge'))->toBe(0)
        ->and(Artisan::output())->toContain('Removed 1 expired idempotency record(s).')
        ->and(DB::table('idempotency_keys')->pluck('idempotency_key')->all())->toBe(['recent']);
});
