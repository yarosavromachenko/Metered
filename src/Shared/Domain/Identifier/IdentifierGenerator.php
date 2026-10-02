<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Identifier;

/**
 * Injected like the clock, so tests control the ids they get.
 */
interface IdentifierGenerator
{
    public function generate(): Uuid;
}
