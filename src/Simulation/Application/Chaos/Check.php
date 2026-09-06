<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Chaos;

/**
 * One invariant a scenario ends with, and whether it held.
 */
final readonly class Check
{
    public function __construct(
        public string $invariant,
        public bool $held,
        public string $detail,
    ) {}
}
