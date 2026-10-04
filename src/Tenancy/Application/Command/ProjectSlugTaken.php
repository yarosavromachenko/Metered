<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Tenancy\Domain\Slug;
use RuntimeException;

/**
 * Project slugs are not auto-suffixed like organization slugs: a duplicate
 * inside one organization is reported.
 */
final class ProjectSlugTaken extends RuntimeException
{
    public static function withSlug(Slug $slug): self
    {
        return new self(sprintf('This organization already has a project called "%s".', $slug->value));
    }
}
