<?php

declare(strict_types=1);

use Metered\Shared\Application\Metrics\Counter;
use Metered\Shared\Application\Metrics\Histogram;
use Metered\Shared\Application\Metrics\Scale;
use Tests\Support\InMemoryMetrics;

it('adds to a counter per label set', function (): void {
    $recorder = new InMemoryMetrics();
    $rejected = new Counter('usage.events.rejected', '{event}', 'Events refused by the consumer');

    $recorder->metrics->add($rejected, 2, ['reason' => 'unknown_meter']);
    $recorder->metrics->add($rejected, 1, ['reason' => 'unknown_meter']);
    $recorder->metrics->add($rejected, 5, ['reason' => 'too_old']);

    expect($recorder->counted('usage.events.rejected', ['reason' => 'unknown_meter']))->toBe(3)
        ->and($recorder->counted('usage.events.rejected', ['reason' => 'too_old']))->toBe(5)
        ->and($recorder->counted('usage.events.rejected', ['reason' => 'malformed']))->toBe(0);
});

it('records a duration given in nanoseconds as seconds', function (): void {
    $recorder = new InMemoryMetrics();
    $duration = Histogram::duration('ingest.duration', 'Time to answer an ingestion request');

    $recorder->metrics->record($duration, 150_000_000, ['status' => '202']);
    $recorder->metrics->record($duration, 50_000_000, ['status' => '202']);

    expect($recorder->histogram('ingest.duration', ['status' => '202']))->toBe(['count' => 2, 'sum' => 0.2]);
});

it('records a count as it is', function (): void {
    $recorder = new InMemoryMetrics();
    $size = new Histogram('usage.batch.size', '{event}', 'Events written per batch', Scale::Count);

    $recorder->metrics->record($size, 500);

    expect($recorder->histogram('usage.batch.size'))->toBe(['count' => 1, 'sum' => 500]);
});
