<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Identifier;

use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid as RamseyUuid;

/**
 * Generates UUIDv7 from the injected clock rather than from the system time the
 * library would read on its own.
 *
 * That matters because the timestamp is part of the identifier. Under a mock
 * clock a test can generate ids at chosen instants and assert that they sort in
 * that order — which is the property the rest of the system relies on.
 */
final readonly class Uuid7Generator implements IdentifierGenerator
{
    public function __construct(private ClockInterface $clock) {}

    public function generate(): Uuid
    {
        return Uuid::fromString(RamseyUuid::uuid7($this->clock->now())->toString());
    }
}
