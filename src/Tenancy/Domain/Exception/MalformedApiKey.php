<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class MalformedApiKey extends DomainException
{
    /**
     * The token itself never reaches the message. A rejected token is still a
     * credential — often a valid one for a neighbouring system — and an
     * exception message ends up in logs, in error trackers and in problem
     * responses.
     */
    public static function badShape(): self
    {
        return new self('The API key is not in the expected format: mk_<environment>_<prefix>_<secret>.');
    }
}
