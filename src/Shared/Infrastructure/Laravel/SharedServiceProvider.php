<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Laravel;

use Illuminate\Support\ServiceProvider;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Infrastructure\Clock\SystemClock;
use Metered\Shared\Infrastructure\Identifier\Uuid7Generator;
use Psr\Clock\ClockInterface;

/**
 * Wires the shared kernel's ports to their production adapters.
 *
 * The module owns its own wiring rather than leaving it in app/Providers, so
 * that everything a module needs to work travels with the module.
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, SystemClock::class);
        $this->app->singleton(IdentifierGenerator::class, Uuid7Generator::class);
    }
}
