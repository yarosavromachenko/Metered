<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Tenancy\Domain\Organization;
use RuntimeException;

/**
 * Checked before deleting anything; the invoicing triggers refuse it too.
 */
final class NotADemoOrganization extends RuntimeException
{
    public static function named(Organization $organization): self
    {
        return new self(sprintf(
            'Organization "%s" was not created as a demo, and only demo organizations are ever purged.',
            $organization->slug->value,
        ));
    }
}
