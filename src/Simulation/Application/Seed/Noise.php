<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

/**
 * Keyed, stateless randomness: same seed and key, same number. The API seed
 * and the history load can compute any hour independently, and a resent hour
 * has the same event ids.
 */
final readonly class Noise
{
    public function __construct(private int $seed) {}

    /**
     * In [0, 1).
     */
    public function unit(string $key): float
    {
        $hash = hash('xxh64', $this->seed . '|' . $key);

        return hexdec(substr($hash, 0, 13)) / 0x10000000000000;
    }

    /**
     * In [min, max].
     */
    public function between(string $key, int $min, int $max): int
    {
        return $min + (int) floor($this->unit($key) * ($max - $min + 1));
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $items
     * @return T
     */
    public function pick(string $key, array $items): mixed
    {
        return $items[$this->between($key, 0, count($items) - 1)];
    }
}
