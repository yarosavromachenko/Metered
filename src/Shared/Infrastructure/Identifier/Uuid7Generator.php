<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Identifier;

use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid as RamseyUuid;

/**
 * UUIDv7 with the timestamp from the injected clock.
 */
final readonly class Uuid7Generator implements IdentifierGenerator
{
    public function __construct(private ClockInterface $clock) {}

    public function generate(): Uuid
    {
        return Uuid::fromString(RamseyUuid::uuid7($this->clock->now())->toString());
    }
}
