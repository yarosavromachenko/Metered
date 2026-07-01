<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\CustomerReference;
use RuntimeException;

final class CustomerReferenceTaken extends RuntimeException
{
    public static function withReference(CustomerReference $reference): self
    {
        return new self(sprintf(
            'This project already has a customer under the reference "%s".',
            $reference->value,
        ));
    }
}
