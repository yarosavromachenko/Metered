<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;

/**
 * Includes the showcase.
 */
final readonly class PurgeAllDemos
{
    public function __construct(public Actor $actor) {}
}
