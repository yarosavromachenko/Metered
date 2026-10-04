<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class MalformedApiKey extends DomainException
{
    /**
     * The message never includes the token: it reaches logs and responses.
     */
    public static function badShape(): self
    {
        return new self('The API key is not in the expected format: mk_<environment>_<prefix>_<secret>.');
    }
}
