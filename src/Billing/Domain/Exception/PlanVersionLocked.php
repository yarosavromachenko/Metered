<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

/**
 * An attempt to change a plan version after it was published.
 *
 * A separate type from the validation failures, because it means something
 * different to the person who hit it: the fix is not a better value but a new
 * version.
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
