<?php

declare(strict_types=1);

namespace Metered\Usage\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidEventQuantity extends DomainException
{
    public static function tooLarge(string $value, string $limit): self
    {
        return new self(sprintf(
            '"%s" is more than one event can carry: a quantity must be less than %s.',
            $value,
            $limit,
        ));
    }
}
