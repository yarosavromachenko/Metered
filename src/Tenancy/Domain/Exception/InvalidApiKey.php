<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidApiKey extends DomainException
{
    public static function withoutScopes(): self
    {
        return new self('An API key must carry at least one scope, or it can authenticate and then do nothing.');
    }
}
