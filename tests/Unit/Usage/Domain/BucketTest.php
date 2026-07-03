<?php

declare(strict_types=1);

use Metered\Usage\Domain\Bucket;

it('puts an event in the hour it happened in', function (string $occurredAt, string $start): void {
    expect(Bucket::containing(new DateTimeImmutable($occurredAt))->start->format(DATE_ATOM))->toBe($start);
})->with([
    'on the hour' => ['2026-09-22T12:00:00+00:00', '2026-09-22T12:00:00+00:00'],
    'mid hour' => ['2026-09-22T12:34:56+00:00', '2026-09-22T12:00:00+00:00'],
    'the last microsecond' => ['2026-09-22T12:59:59.999999+00:00', '2026-09-22T12:00:00+00:00'],
    'midnight' => ['2026-09-22T00:00:12+00:00', '2026-09-22T00:00:00+00:00'],
]);

it('buckets by the instant, not by the offset it arrived in', function (): void {
    // 15:30 in Moscow is 12:30 UTC, and aggregates are UTC throughout: two
    // clients in different zones reporting the same moment must land in one
    // bucket, or an invoice would count the hour twice.
    $moscow = Bucket::containing(new DateTimeImmutable('2026-09-22T15:30:00+03:00'));
    $utc = Bucket::containing(new DateTimeImmutable('2026-09-22T12:30:00+00:00'));

    expect($moscow->equals($utc))->toBeTrue()
        ->and($moscow->start->getTimezone()->getName())->toBe('UTC');
});

it('drops the microseconds the aggregate key cannot hold', function (): void {
    expect(Bucket::containing(new DateTimeImmutable('2026-09-22T12:34:56.789012+00:00'))->start->format('u'))
        ->toBe('000000');
});

it('separates neighbouring hours', function (): void {
    $noon = Bucket::containing(new DateTimeImmutable('2026-09-22T12:59:59+00:00'));
    $one = Bucket::containing(new DateTimeImmutable('2026-09-22T13:00:00+00:00'));

    expect($noon->equals($one))->toBeFalse();
});
