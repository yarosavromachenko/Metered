<?php

declare(strict_types=1);

use Metered\Webhooks\Domain\Endpoint\BreakerState;
use Metered\Webhooks\Domain\Endpoint\CircuitBreaker;

const BREAKER_THRESHOLD = 5;
const BREAKER_COOLDOWN = 300;

function failTimes(CircuitBreaker $breaker, int $times, DateTimeImmutable $at): CircuitBreaker
{
    for ($i = 0; $i < $times; ++$i) {
        $breaker = $breaker->failed($at, BREAKER_THRESHOLD);
    }

    return $breaker;
}

it('walks closed → open → half-open → closed', function (): void {
    $t0 = new DateTimeImmutable('2026-09-25T12:00:00Z');
    $breaker = CircuitBreaker::closed();

    $almost = failTimes($breaker, BREAKER_THRESHOLD - 1, $t0);
    $open = $almost->failed($t0, BREAKER_THRESHOLD);
    $probe = $open->admit($t0->modify('+300 seconds'), BREAKER_COOLDOWN);
    $closed = $probe?->succeeded();

    expect($almost->state)->toBe(BreakerState::Closed)
        ->and($almost->consecutiveFailures)->toBe(4)
        ->and($almost->admit($t0, BREAKER_COOLDOWN))->toBe($almost)
        ->and($open->state)->toBe(BreakerState::Open)
        ->and($open->reopensAt(BREAKER_COOLDOWN)?->format(DATE_ATOM))->toBe('2026-09-25T12:05:00+00:00')
        ->and($open->admit($t0->modify('+299 seconds'), BREAKER_COOLDOWN))->toBeNull()
        ->and($probe?->state)->toBe(BreakerState::HalfOpen)
        ->and($closed?->state)->toBe(BreakerState::Closed)
        ->and($closed?->consecutiveFailures)->toBe(0)
        ->and($closed?->reopensAt(BREAKER_COOLDOWN))->toBeNull();
});

it('lets exactly one probe out while half-open, and opens again when it fails', function (): void {
    $t0 = new DateTimeImmutable('2026-09-25T12:00:00Z');
    $probe = failTimes(CircuitBreaker::closed(), BREAKER_THRESHOLD, $t0)->admit($t0->modify('+5 minutes'), BREAKER_COOLDOWN);

    $second = $probe?->admit($t0->modify('+6 minutes'), BREAKER_COOLDOWN);
    $reopened = $probe?->failed($t0->modify('+6 minutes'), BREAKER_THRESHOLD);

    expect($second)->toBeNull()
        ->and($reopened?->state)->toBe(BreakerState::Open)
        ->and($reopened?->reopensAt(BREAKER_COOLDOWN)?->format(DATE_ATOM))->toBe('2026-09-25T12:11:00+00:00');
});

it('replaces a probe that never reported, one cooldown later', function (): void {
    $t0 = new DateTimeImmutable('2026-09-25T12:00:00Z');
    $probe = failTimes(CircuitBreaker::closed(), BREAKER_THRESHOLD, $t0)->admit($t0->modify('+5 minutes'), BREAKER_COOLDOWN);

    expect($probe?->admit($t0->modify('+10 minutes'), BREAKER_COOLDOWN)?->state)->toBe(BreakerState::HalfOpen)
        ->and($probe?->admit($t0->modify('+10 minutes'), BREAKER_COOLDOWN)?->changedAt?->format(DATE_ATOM))->toBe('2026-09-25T12:10:00+00:00');
});

it('forgets failures once a delivery succeeds', function (): void {
    $t0 = new DateTimeImmutable('2026-09-25T12:00:00Z');
    $breaker = failTimes(CircuitBreaker::closed(), BREAKER_THRESHOLD - 1, $t0)->succeeded();

    expect(failTimes($breaker, BREAKER_THRESHOLD - 1, $t0)->state)->toBe(BreakerState::Closed);
});

it('has no reopening time while closed, whatever was stored', function (): void {
    expect(CircuitBreaker::restore(BreakerState::Closed, 2, new DateTimeImmutable('2026-09-25T12:00:00Z'))->reopensAt(BREAKER_COOLDOWN))->toBeNull()
        ->and(CircuitBreaker::restore(BreakerState::Open, 5, null)->reopensAt(BREAKER_COOLDOWN))->toBeNull()
        ->and(CircuitBreaker::restore(BreakerState::Open, 5, null)->admit(new DateTimeImmutable(), BREAKER_COOLDOWN)?->state)->toBe(BreakerState::HalfOpen);
});
