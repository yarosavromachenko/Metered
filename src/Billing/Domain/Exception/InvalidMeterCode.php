<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidMeterCode extends DomainException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            '"%s" is not a valid meter code: lowercase letters, digits, and single dots, '
            . 'hyphens or underscores between them.',
            $value,
        ));
    }

    public static function tooShort(string $value): self
    {
        return new self(sprintf('"%s" is too short for a meter code: at least 2 characters.', $value));
    }

    public static function tooLong(string $value): self
    {
        return new self(sprintf('That meter code is too long: at most 64 characters, %d given.', strlen($value)));
    }
}
