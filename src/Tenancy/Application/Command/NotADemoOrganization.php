<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Tenancy\Domain\Organization;
use RuntimeException;

/**
 * Raised when a purge names an organization that was not created as a demo.
 *
 * The invoicing triggers would refuse it anyway; this says so before anything
 * is deleted, in a sentence rather than a trigger's exception.
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
