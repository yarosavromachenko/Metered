<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidCustomerReference extends DomainException
{
    public static function empty(): self
    {
        return new self('A customer reference cannot be empty: it is the id the tenant knows them by.');
    }

    public static function malformed(string $value): self
    {
        return new self(sprintf(
            '"%s" is not a valid customer reference: no spaces, no control characters.',
            $value,
        ));
    }

    public static function tooLong(string $value): self
    {
        return new self(sprintf(
            'That customer reference is too long: at most 128 characters, %d given.',
            strlen($value),
        ));
    }
}
