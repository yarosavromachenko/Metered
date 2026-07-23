<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Plan\PlanCode;
use RuntimeException;

final class PlanCodeTaken extends RuntimeException
{
    public static function withCode(PlanCode $code): self
    {
        return new self(sprintf('This project already has a plan with the code "%s".', $code->value));
    }
}
