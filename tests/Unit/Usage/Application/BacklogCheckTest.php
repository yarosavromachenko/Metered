<?php

declare(strict_types=1);

use Metered\Usage\Application\Stream\BacklogCheck;
use Metered\Usage\Application\Stream\StreamDepth;

function backlogOf(int $pending): StreamDepth
{
    return new readonly class ($pending) implements StreamDepth {
        public function __construct(private int $pending) {}

        public function pending(): int
        {
            return $this->pending;
        }
    };
}

it('decides at the same depth as backpressure', function (int $pending, bool $passed): void {
    $result = new BacklogCheck(backlogOf($pending), 100)->check();

    expect($result->passed)->toBe($passed)
        ->and($result->detail)->toBe($pending . ' pending of 100');
})->with([
    'empty' => [0, true],
    'one below the threshold' => [99, true],
    // Ingestion answers 503 from this depth on, so the instance is not ready.
    'at the threshold' => [100, false],
    'past it' => [101, false],
]);

it('reports under a stable name', function (): void {
    expect(new BacklogCheck(backlogOf(0), 1)->name())->toBe('usage_backlog');
});
