<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\CustomerReference;
use Metered\Shared\Application\Exception\Conflict;
use RuntimeException;

final class CustomerReferenceTaken extends RuntimeException implements Conflict
{
    public static function withReference(CustomerReference $reference): self
    {
        return new self(sprintf(
            'This project already has a customer under the reference "%s".',
            $reference->value,
        ));
    }
}
