<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\MeterCode;
use RuntimeException;

/**
 * Reported rather than resolved. A second meter under an existing code would
 * split one client's usage across two definitions, and the person defining it
 * is the only one who knows which of the two they meant.
 */
final class MeterCodeTaken extends RuntimeException
{
    public static function withCode(MeterCode $code): self
    {
        return new self(sprintf('This project already has a meter with the code "%s".', $code->value));
    }
}
