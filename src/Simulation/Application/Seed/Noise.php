<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

/**
 * Deterministic randomness: the same seed and the same key always give the
 * same number.
 *
 * Not a generator with state, deliberately. The live API seed and the
 * backfill produce different stretches of one customer's history in separate
 * processes; keyed noise lets both compute any hour on its own and agree on
 * it, and resending an hour produces the same event ids, which ingestion then
 * deduplicates rather than counting twice.
 */
final readonly class Noise
{
    public function __construct(private int $seed) {}

    /**
     * A number in [0, 1).
     */
    public function unit(string $key): float
    {
        $hash = hash('xxh64', $this->seed . '|' . $key);

        return hexdec(substr($hash, 0, 13)) / 0x10000000000000;
    }

    /**
     * An integer in [min, max].
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
