<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Simulation\Infrastructure\Http\HttpMeteredApi;

function simulationClient(): HttpMeteredApi
{
    return new HttpMeteredApi(app(Factory::class), app(IdentifierGenerator::class), 'http://metered.test', 'mk_test_client');
}

beforeEach(function (): void {
    Sleep::fake();
});

it('waits out a spent rate limit for as long as Retry-After asks, then resends the batch', function (): void {
    Http::fake(['metered.test/api/v1/usage/events' => Http::sequence()
        ->push(['title' => 'Too Many Requests'], 429, ['Retry-After' => '42'])
        ->push(['accepted' => 2], 202),
    ]);

    expect(simulationClient()->ingest([[['id' => 'a'], ['id' => 'b']]]))->toBe(2);

    Sleep::assertSequence([Sleep::for(42)->seconds()]);
    Http::assertSentCount(2);
});

it('waits a second when the answer names no wait, and never more than a minute', function (string $retryAfter, int $seconds): void {
    Http::fake(['metered.test/api/v1/usage/events' => Http::sequence()
        ->push([], 503, $retryAfter === '' ? [] : ['Retry-After' => $retryAfter])
        ->push(['accepted' => 1], 202),
    ]);

    simulationClient()->ingest([[['id' => 'a']]]);

    Sleep::assertSlept(static fn(CarbonInterval $waited): bool => (int) $waited->totalSeconds === $seconds, 1);
})->with([
    'no Retry-After' => ['', 1],
    'an hour' => ['3600', 60],
]);

it('sends batches as they are produced rather than taking them all first', function (): void {
    Http::fake(['metered.test/api/v1/usage/events' => Http::response(['accepted' => 1], 202)]);
    $sentWhenProduced = [];

    // A seed's live day is a quarter of a million events: the client must
    // be able to take them from a generator a window at a time, or the whole
    // day has to sit in memory before the first request leaves.
    $batches = (static function () use (&$sentWhenProduced): Generator {
        foreach (range(1, 200) as $index) {
            $sentWhenProduced[$index] = count(Http::recorded());

            yield [['id' => 'event-' . $index]];
        }
    })();

    expect(simulationClient()->ingest($batches))->toBe(200)
        ->and($sentWhenProduced[200])->toBeGreaterThan(100);

    Http::assertSentCount(200);
});
