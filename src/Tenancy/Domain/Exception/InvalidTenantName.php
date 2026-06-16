<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidTenantName extends DomainException
{
    public static function empty(string $subject): self
    {
        return new self(sprintf('The name of a %s cannot be empty.', $subject));
    }

    public static function tooLong(string $subject, int $limit): self
    {
        return new self(sprintf('The name of a %s is at most %d characters.', $subject, $limit));
    }
}
