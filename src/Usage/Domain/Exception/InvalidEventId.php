<?php

declare(strict_types=1);

namespace Metered\Usage\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidEventId extends DomainException
{
    public static function empty(): self
    {
        return new self('An event needs an id: it is what makes sending it twice safe.');
    }

    public static function malformed(string $value): self
    {
        return new self(sprintf(
            '"%s" is not a valid event id: no spaces, no control characters.',
            $value,
        ));
    }

    public static function tooLong(string $value): self
    {
        return new self(sprintf('That event id is too long: at most 128 characters, %d given.', strlen($value)));
    }
}
