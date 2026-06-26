<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Tenancy\Domain\Slug;
use RuntimeException;

/**
 * Unlike organization slugs, a project slug is not quietly suffixed. Inside
 * one organization the names are chosen by colleagues who can see each other's
 * projects, and two things called "production" is a mistake worth reporting
 * rather than papering over.
 */
final class ProjectSlugTaken extends RuntimeException
{
    public static function withSlug(Slug $slug): self
    {
        return new self(sprintf('This organization already has a project called "%s".', $slug->value));
    }
}
