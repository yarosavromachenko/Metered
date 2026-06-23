<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use RuntimeException;

final class EmailAlreadyRegistered extends RuntimeException
{
    public static function withEmail(string $email): self
    {
        return new self(sprintf('An account already exists for %s.', $email));
    }
}
