<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;

final readonly class PurgeIdleDemos
{
    public function __construct(public Actor $actor) {}
}
