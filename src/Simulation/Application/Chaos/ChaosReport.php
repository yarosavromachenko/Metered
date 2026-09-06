<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Chaos;

final readonly class ChaosReport
{
    /**
     * @param  list<Check>  $checks
     * @param  list<string>  $notes  what happened on the way, for the reader
     */
    public function __construct(
        public Scenario $scenario,
        public string $organizationSlug,
        public array $checks,
        public array $notes,
    ) {}

    public function held(): bool
    {
        return array_filter($this->checks, static fn(Check $check): bool => ! $check->held) === [];
    }
}
