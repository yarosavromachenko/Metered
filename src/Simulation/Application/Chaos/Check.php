<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Chaos;

final readonly class Check
{
    public function __construct(
        public string $invariant,
        public bool $held,
        public string $detail,
    ) {}
}
