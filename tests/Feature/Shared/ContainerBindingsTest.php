<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Infrastructure\Clock\SystemClock;
use Metered\Shared\Infrastructure\Identifier\Uuid7Generator;
use Psr\Clock\ClockInterface;

/*
 * Ports are useless if nothing binds them. These tests fail the moment a
 * provider is dropped from bootstrap/providers.php — which is otherwise the
 * kind of mistake that only surfaces at runtime, in whichever request first
 * asks for the time.
 */

it('binds the clock port to the system clock', function (): void {
    expect(app(ClockInterface::class))->toBeInstanceOf(SystemClock::class);
});

it('binds the identifier port to the version 7 generator', function (): void {
    expect(app(IdentifierGenerator::class))->toBeInstanceOf(Uuid7Generator::class);
});

it('resolves the clock once per container', function (): void {
    expect(app(ClockInterface::class))->toBe(app(ClockInterface::class));
});

it('hands the generator a working clock, so it produces usable identifiers', function (): void {
    $id = app(IdentifierGenerator::class)->generate();

    expect($id)->toBeInstanceOf(Uuid::class)
        ->and($id->version())->toBe(7);
});
