<?php

declare(strict_types=1);

namespace Metered\Usage\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidProperties extends DomainException
{
    public static function unnamed(): self
    {
        return new self('Every property has to be named: {"region": "eu-central"}, not a list.');
    }

    public static function nested(string $key): self
    {
        return new self(sprintf(
            'The property "%s" has to be a single value, not a nested one. '
            . 'Properties label an event; they do not carry a document.',
            $key,
        ));
    }

    public static function tooMany(int $count): self
    {
        return new self(sprintf('An event carries at most 32 properties, %d given.', $count));
    }

    public static function valueTooLong(string $key): self
    {
        return new self(sprintf('The property "%s" is too long: at most 256 characters.', $key));
    }
}
