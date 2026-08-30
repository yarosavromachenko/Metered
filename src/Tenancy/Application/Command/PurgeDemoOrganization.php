<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Remove a demo organization and everything it ever held, invoices included.
 */
final readonly class PurgeDemoOrganization
{
    public function __construct(
        public Uuid $organizationId,
        public Actor $actor,
    ) {}
}
