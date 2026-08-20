<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidEndpoint extends DomainException
{
    public static function url(string $reason): self
    {
        return new self(sprintf('That webhook URL cannot be used: %s.', $reason));
    }

    public static function noEvents(): self
    {
        return new self('A webhook endpoint must listen to at least one event.');
    }

    public static function secret(): self
    {
        return new self('A webhook secret is "whsec_" followed by at least 32 URL-safe characters.');
    }

    public static function descriptionTooLong(int $limit): self
    {
        return new self(sprintf('A webhook description is at most %d characters.', $limit));
    }
}
