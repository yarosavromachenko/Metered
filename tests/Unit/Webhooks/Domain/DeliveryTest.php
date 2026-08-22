<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Delivery\Delivery;
use Metered\Webhooks\Domain\Delivery\DeliveryStatus;
use Metered\Webhooks\Domain\Delivery\RetrySchedule;
use Metered\Webhooks\Domain\Delivery\Verdict;
use Metered\Webhooks\Domain\Endpoint\EventType;
use Metered\Webhooks\Domain\Exception\DeliveryRefused;

function scheduledDelivery(): Delivery
{
    return Delivery::schedule(
        Uuid::fromString('01924b7c-0000-7000-8000-00000000c101'),
        new TenantContext(Uuid::fromString('01924b7c-0000-7000-8000-00000000c090'), Uuid::fromString('01924b7c-0000-7000-8000-00000000c091')),
        Uuid::fromString('01924b7c-0000-7000-8000-00000000c001'),
        Uuid::fromString('01924b7c-0000-7000-8000-00000000c201'),
        EventType::InvoicePaid,
        '{"id":"x"}',
        new DateTimeImmutable('2026-09-25T12:00:00Z'),
    );
}

it('judges what came back', function (AttemptResult $result, Verdict $verdict): void {
    expect($result->verdict())->toBe($verdict);
})->with([
    '200' => [AttemptResult::responded(200, 12, 'ok'), Verdict::Delivered],
    '204' => [AttemptResult::responded(204, 12, ''), Verdict::Delivered],
    '299' => [AttemptResult::responded(299, 12, ''), Verdict::Delivered],
    '199' => [AttemptResult::responded(199, 12, ''), Verdict::GiveUp],
    '300' => [AttemptResult::responded(300, 12, ''), Verdict::GiveUp],
    'a redirect' => [AttemptResult::responded(302, 12, ''), Verdict::GiveUp],
    'bad request' => [AttemptResult::responded(400, 12, ''), Verdict::GiveUp],
    'gone' => [AttemptResult::responded(410, 12, ''), Verdict::GiveUp],
    'timeout at the receiver' => [AttemptResult::responded(408, 12, ''), Verdict::Retry],
    'too many requests' => [AttemptResult::responded(429, 12, ''), Verdict::Retry],
    '499' => [AttemptResult::responded(499, 12, ''), Verdict::GiveUp],
    'server error' => [AttemptResult::responded(500, 12, ''), Verdict::Retry],
    'bad gateway' => [AttemptResult::responded(502, 12, ''), Verdict::Retry],
    'no answer' => [AttemptResult::unreachable('connection timed out', 10_000), Verdict::Retry],
    'refused address' => [AttemptResult::refused('resolves to 10.0.0.5'), Verdict::GiveUp],
]);

it('keeps at most a kilobyte of what the receiver said', function (): void {
    $result = AttemptResult::responded(500, 3, str_repeat('é', 1000));

    expect(strlen($result->responseExcerpt))->toBe(1024)
        ->and($result->responseExcerpt)->toBe(str_repeat('é', 512))
        ->and(AttemptResult::responded(200, 1, 'short')->responseExcerpt)->toBe('short')
        ->and($result->durationMs)->toBe(3)
        ->and($result->statusCode)->toBe(500)
        ->and($result->error)->toBeNull();

    $unreachable = AttemptResult::unreachable('dns', 7);
    $refused = AttemptResult::refused('private');

    expect([$unreachable->error, $unreachable->durationMs, $unreachable->responseExcerpt, $unreachable->statusCode, $unreachable->refusedDestination])
        ->toBe(['dns', 7, '', null, false])
        ->and([$refused->error, $refused->durationMs, $refused->responseExcerpt, $refused->statusCode, $refused->refusedDestination])
        ->toBe(['private', 0, '', null, true]);
});

it('waits 1m, 5m, 30m, 1h, 2h, 4h, 8h, 12h and 24h, then gives up after the tenth attempt', function (): void {
    $at = new DateTimeImmutable('2026-09-25T12:00:00Z');
    $waits = [];

    foreach (range(1, 9) as $failed) {
        $waits[] = (int) RetrySchedule::nextAttemptAt($failed, $at, 0)?->getTimestamp() - $at->getTimestamp();
    }

    expect($waits)->toBe([60, 300, 1_800, 3_600, 7_200, 14_400, 28_800, 43_200, 86_400])
        ->and(RetrySchedule::nextAttemptAt(10, $at, 0))->toBeNull();
});

it('moves each wait by at most a fifth either way', function (int $jitter, int $seconds): void {
    $at = new DateTimeImmutable('2026-09-25T12:00:00Z');

    expect((int) RetrySchedule::nextAttemptAt(2, $at, $jitter)?->getTimestamp() - $at->getTimestamp())->toBe($seconds);
})->with([
    'earliest' => [-200, 240],
    'latest' => [200, 360],
    'clamped below' => [-900, 240],
    'clamped above' => [900, 360],
    'a little late' => [1, 300],
]);

it('succeeds on a 2xx, counting the attempt', function (): void {
    $done = scheduledDelivery()->attempted(AttemptResult::responded(200, 5, ''), new DateTimeImmutable('2026-09-25T12:00:01Z'), 0);

    expect($done->status)->toBe(DeliveryStatus::Succeeded)
        ->and($done->attempts)->toBe(1)
        ->and($done->nextAttemptAt)->toBeNull()
        ->and($done->lastStatusCode)->toBe(200)
        ->and($done->isDueAt(new DateTimeImmutable('2030-01-01')))->toBeFalse();
});

it('fails for good when the receiver refuses it', function (): void {
    $failed = scheduledDelivery()->attempted(AttemptResult::responded(410, 5, 'gone'), new DateTimeImmutable('2026-09-25T12:00:01Z'), 0);

    expect($failed->status)->toBe(DeliveryStatus::Failed)->and($failed->lastStatusCode)->toBe(410);
});

it('dies after ten failures in a row, and can then be replayed from the start', function (): void {
    $delivery = scheduledDelivery();
    $at = new DateTimeImmutable('2026-09-25T12:00:00Z');

    foreach (range(1, 9) as $attempt) {
        $delivery = $delivery->attempted(AttemptResult::responded(503, 5, ''), $at, 0);
        expect($delivery->status)->toBe(DeliveryStatus::Pending)->and($delivery->attempts)->toBe($attempt);
        $at = $delivery->nextAttemptAt ?? $at;
    }

    $dead = $delivery->attempted(AttemptResult::unreachable('timeout', 10_000), $at, 0);
    $replayed = $dead->replay(new DateTimeImmutable('2026-09-30T00:00:00Z'));

    expect($dead->status)->toBe(DeliveryStatus::Dead)
        ->and($dead->attempts)->toBe(10)
        ->and($dead->nextAttemptAt)->toBeNull()
        ->and($dead->lastStatusCode)->toBeNull()
        ->and($replayed->status)->toBe(DeliveryStatus::Pending)
        ->and($replayed->attempts)->toBe(0)
        ->and($replayed->body)->toBe('{"id":"x"}')
        ->and($replayed->isDueAt(new DateTimeImmutable('2026-09-30T00:00:00Z')))->toBeTrue()
        ->and($replayed->isDueAt(new DateTimeImmutable('2026-09-29T23:59:59Z')))->toBeFalse();
});

it('waits for an open breaker without counting an attempt', function (): void {
    $later = new DateTimeImmutable('2026-09-25T12:05:00Z');
    $waiting = scheduledDelivery()->postponedUntil($later);

    expect($waiting->status)->toBe(DeliveryStatus::Pending)
        ->and($waiting->attempts)->toBe(0)
        ->and($waiting->nextAttemptAt)->toBe($later);
});

it('replays only what is dead or failed', function (Closure $delivery): void {
    /** @var Closure(): Delivery $delivery */
    expect(static fn(): Delivery => $delivery()->replay(new DateTimeImmutable()))->toThrow(DeliveryRefused::class, 'cannot be replayed');
})->with([
    'pending' => [scheduledDelivery(...)],
    'succeeded' => [static fn(): Delivery => scheduledDelivery()->attempted(AttemptResult::responded(200, 1, ''), new DateTimeImmutable(), 0)],
]);

it('replays a failed delivery too', function (): void {
    $failed = scheduledDelivery()->attempted(AttemptResult::refused('private'), new DateTimeImmutable(), 0);

    expect($failed->replay(new DateTimeImmutable())->status)->toBe(DeliveryStatus::Pending);
});

it('waits no less than a minute after the first failure, however it is counted', function (): void {
    $at = new DateTimeImmutable('2026-09-25T12:00:00Z');

    expect((int) RetrySchedule::nextAttemptAt(0, $at, 0)?->getTimestamp() - $at->getTimestamp())->toBe(60)
        ->and(RetrySchedule::MAX_ATTEMPTS)->toBe(10)
        ->and(RetrySchedule::JITTER_PERMILLE)->toBe(200);
});
