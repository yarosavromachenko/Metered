<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Identifier;

/**
 * The port through which anything obtains a new identifier.
 *
 * Generation is a dependency rather than a static call for the same reason time
 * is: a test that cannot control the ids it is given cannot assert on ordering,
 * and an entity that mints its own id hides where that id came from.
 */
interface IdentifierGenerator
{
    public function generate(): Uuid;
}
