<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Clock;

use Illuminate\Contracts\Cache\Repository;

/**
 * Demo clock offset in seconds. Stored in the cache so every process of the
 * stack sees the same time.
 */
final readonly class ClockOffset
{
    private const string KEY = 'metered:clock:offset';

    public function __construct(private Repository $cache) {}

    public function seconds(): int
    {
        $value = $this->cache->get(self::KEY);

        // Redis returns the number as a string, the array store as an int.
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
