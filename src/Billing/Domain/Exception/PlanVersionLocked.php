<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

/**
 * A published version cannot change; the fix is a new version.
 */
final class PlanVersionLocked extends DomainException
{
    public static function published(int $number): self
    {
        return new self(sprintf(
            'Version %d is published and can no longer change; publish a new version instead.',
            $number,
        ));
    }
}
