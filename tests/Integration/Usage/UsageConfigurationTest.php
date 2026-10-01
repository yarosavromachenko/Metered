<?php

declare(strict_types=1);

/**
 * Settings that are only correct together.
 *
 * Each is an environment variable and can be changed alone, which is exactly
 * how a pair like this comes apart: nothing fails, and the guarantee they
 * made together quietly stops holding.
 */
it('remembers a claim for at least as long as an event can still arrive', function (): void {
    // A resend with a corrected timestamp is caught only while the first
    // claim exists. If claims expired before the acceptance window closed, a
    // resend in the gap would be accepted and counted twice (ADR-0002).
    expect(config()->integer('metered.usage.deduplication.ttl_seconds'))
        ->toBeGreaterThanOrEqual(config()->integer('metered.usage.acceptance.max_age_seconds'));
});

it('sheds load well before the stream trims what it has not written', function (): void {
    // MAXLEN ~ trims the oldest entries whether or not they were written.
    // Backpressure has to stop the backlog long before it reaches the length
    // the stream is trimmed at, or trimming would discard accepted events
    // (ADR-0003).
    expect(config()->integer('metered.usage.stream.backpressure_threshold'))
        ->toBeLessThanOrEqual(intdiv(config()->integer('metered.usage.stream.max_length'), 2));
});

/**
 * The usage connection as config/database.php builds it from these variables.
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function usageRedisWith(array $variables): array
{
    // Unset everywhere env() looks, the process environment included: compose
    // sets REDIS_USAGE_HOST on the containers.
    $names = ['REDIS_URL', 'REDIS_USAGE_URL', 'REDIS_USAGE_HOST'];
    $saved = [];

    foreach ($names as $name) {
        $process = getenv($name);
        $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, $process === false ? null : $process];
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }

    foreach ($variables as $name => $value) {
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    try {
        /** @var array{redis: array{usage: array<string, mixed>}} $database */
        $database = require config_path('database.php');

        return $database['redis']['usage'];
    } finally {
        foreach ($saved as $name => [$server, $env, $process]) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($process === null ? $name : $name . '=' . $process);

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($env !== null) {
                $_ENV[$name] = $env;
            }
        }
    }
}

it('keeps ingestion on its own Redis when the main one is given as a URL', function (): void {
    // A URL wins over host and port, so inheriting it would quietly put the
    // stream back on the main Redis.
    $usage = usageRedisWith(['REDIS_URL' => 'redis://main:6379', 'REDIS_USAGE_HOST' => 'redis-usage']);

    expect($usage['url'])->toBeNull()
        ->and($usage['host'])->toBe('redis-usage');
});

it('shares the main Redis, URL and all, when no usage Redis is named', function (): void {
    expect(usageRedisWith(['REDIS_URL' => 'redis://main:6379'])['url'])->toBe('redis://main:6379')
        ->and(usageRedisWith(['REDIS_URL' => 'redis://main:6379', 'REDIS_USAGE_URL' => 'redis://usage:6379'])['url'])->toBe('redis://usage:6379');
});
