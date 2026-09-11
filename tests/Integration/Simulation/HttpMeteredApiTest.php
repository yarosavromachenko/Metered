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
