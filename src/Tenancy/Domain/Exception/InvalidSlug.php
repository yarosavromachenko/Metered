<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidSlug extends DomainException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            '"%s" is not a valid slug: lowercase letters, digits and single hyphens between them.',
            $value,
        ));
    }

    public static function tooShort(string $value): self
    {
        return new self(sprintf('"%s" is too short for a slug: at least 2 characters.', $value));
    }

    public static function tooLong(string $value): self
    {
        return new self(sprintf('"%s" is too long for a slug: at most 63 characters.', $value));
    }

    public static function nothingToSlug(string $name): self
    {
        return new self(sprintf('"%s" has no letters or digits to build a slug from.', $name));
    }
}
