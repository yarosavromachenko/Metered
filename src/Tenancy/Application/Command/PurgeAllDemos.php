<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;

/**
 * Purge every demo organization, the showcase and visitors' tenants alike.
 */
final readonly class PurgeAllDemos
{
    public function __construct(public Actor $actor) {}
}
