<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Idempotency\IdempotencyStore;
use Spatie\Fork\Fork;

/*
 * These tests run in real, separate processes with their own database
 * connections, and they commit.
 *
 * That is not incidental. Wrapped in the usual test transaction they would
 * prove the opposite of what they claim: every "process" would see the others'
 * uncommitted rows, and a race that fails in production would pass here.
 */

$scope = 'concurrency-test';

afterEach(function () use ($scope): void {
    DB::table('idempotency_keys')->where('scope', $scope)->delete();
});

it('lets exactly one of many identical requests through', function () use ($scope): void {
    $key = 'race-' . bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'POST /subscriptions {"plan":"pro"}');

    $attempts = array_map(
        static fn(): Closure => static function () use ($scope, $key, $fingerprint): string {
            // A forked child inherits the parent's connection socket. Sharing
            // one would serialise the very race this test exists to create.
            DB::purge(testConnection());

            return app(IdempotencyStore::class)->claim($scope, $key, $fingerprint)->status->name;
        },
        range(1, 16),
    );

    /** @var list<string> $outcomes */
    $outcomes = Fork::new()->run(...$attempts);

    $counts = array_count_values($outcomes);

    expect($counts['Claimed'] ?? 0)->toBe(1)
        ->and($counts['InProgress'] ?? 0)->toBe(15)
        ->and(DB::table('idempotency_keys')->where('scope', $scope)->where('idempotency_key', $key)->count())->toBe(1);
});

it('keeps different keys independent under the same load', function () use ($scope): void {
    $fingerprint = hash('sha256', 'POST /subscriptions');

    $attempts = array_map(
        static fn(int $i): Closure => static function () use ($scope, $fingerprint, $i): string {
            DB::purge(testConnection());

            return app(IdempotencyStore::class)->claim($scope, 'independent-' . $i, $fingerprint)->status->name;
        },
        range(1, 8),
    );

    /** @var list<string> $outcomes */
    $outcomes = Fork::new()->run(...$attempts);

    // Eight distinct keys, eight claims: the constraint serialises collisions,
    // not traffic.
    expect(array_count_values($outcomes)['Claimed'] ?? 0)->toBe(8);
});
