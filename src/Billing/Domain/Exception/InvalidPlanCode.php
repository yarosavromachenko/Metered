<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidPlanCode extends DomainException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            '"%s" is not a valid plan code: lowercase letters and digits, '
            . 'with single hyphens or underscores between them.',
            $value,
        ));
    }

    public static function tooShort(string $value): self
    {
        return new self(sprintf('"%s" is too short for a plan code: at least 2 characters.', $value));
    }

    public static function tooLong(string $value): self
    {
        return new self(sprintf('That plan code is too long: at most 64 characters, %d given.', strlen($value)));
    }
}
