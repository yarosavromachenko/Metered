<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Clock;

use Illuminate\Contracts\Cache\Repository;

/**
 * How far the demo's clock has been moved ahead of real time, in seconds.
 *
 * Kept in the shared cache rather than in a process, because every process of
 * the stack — the app, Horizon's workers, the consumer, the scheduler, a
 * console command — has to agree on what time it is, or a period would close
 * in one and its usage be refused as too new by another.
 */
final readonly class ClockOffset
{
    private const string KEY = 'metered:clock:offset';

    public function __construct(private Repository $cache) {}

    public function seconds(): int
    {
        $value = $this->cache->get(self::KEY);

        // The Redis store keeps numbers unserialised and hands them back as
        // strings; an array store hands back the int it was given.
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => 0,
        };
    }

    public function set(int $seconds): void
    {
        $seconds === 0 ? $this->cache->forget(self::KEY) : $this->cache->forever(self::KEY, $seconds);
    }
}
