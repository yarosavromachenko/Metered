<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\ParallelTesting;

/**
 * The names of the ingestion stream, its dead-letter stream and its consumer
 * group, as this test process should use them.
 *
 * Under `--parallel` every process has its own database, and all of them share
 * one Redis. A single stream would let one process's consumer read another's
 * events and try to write them against projects its own database has never
 * seen. So each process takes its own names, suffixed with its token, and the
 * tests read them from here rather than spelling them out.
 */
final class UsageStream
{
    public static function isolate(): void
    {
        $token = ParallelTesting::token();

        if ($token === false) {
            return;
        }

        config([
            'metered.usage.stream.key' => 'usage:events:test-' . $token,
            'metered.usage.stream.dead_letter_key' => 'usage:events:test-' . $token . ':dead',
            'metered.usage.stream.group' => 'usage-writers-test-' . $token,
        ]);
    }

    public static function key(): string
    {
        return config()->string('metered.usage.stream.key');
    }

    public static function deadLetter(): string
    {
        return config()->string('metered.usage.stream.dead_letter_key');
    }

    public static function group(): string
    {
        return config()->string('metered.usage.stream.group');
    }
}
