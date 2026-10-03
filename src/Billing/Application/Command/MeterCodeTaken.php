<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\MeterCode;
use Metered\Shared\Application\Exception\Conflict;
use RuntimeException;

final class MeterCodeTaken extends RuntimeException implements Conflict
{
    public static function withCode(MeterCode $code): self
    {
        return new self(sprintf('This project already has a meter with the code "%s".', $code->value));
    }
}
