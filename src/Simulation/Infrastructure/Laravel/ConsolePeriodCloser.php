<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Illuminate\Contracts\Console\Kernel;
use Metered\Simulation\Application\Port\PeriodCloser;
use RuntimeException;

final readonly class ConsolePeriodCloser implements PeriodCloser
{
    public function __construct(private Kernel $console) {}

    public function closeDue(): void
    {
        if ($this->console->call('billing:close-periods', ['--sync' => true]) !== 0) {
            throw new RuntimeException('billing:close-periods failed: ' . trim($this->console->output()));
        }
    }
}
