<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidPricing extends DomainException
{
    public static function negativeFlatFee(string $amount): self
    {
        return new self(sprintf('A flat fee cannot be negative, %s given.', $amount));
    }

    public static function noTiers(): self
    {
        return new self('A tiered price needs at least one tier.');
    }

    public static function lastTierBounded(): self
    {
        return new self('The last tier must be unbounded, or a quantity beyond it would have no price.');
    }

    public static function unboundedTierBeforeLast(int $position): self
    {
        return new self(sprintf('Only the last tier may be unbounded; tier %d is.', $position));
    }

    public static function limitsMustRise(int $position): self
    {
        return new self(sprintf(
            'Tier limits must rise: tier %d ends at or below where the one before it ends.',
            $position,
        ));
    }

    public static function mixedCurrencies(string $first, string $other): self
    {
        return new self(sprintf('A price is set in one currency; its tiers mix %s and %s.', $first, $other));
    }

    public static function meterOnFixedCharge(): self
    {
        return new self('A fixed charge does not depend on usage, so it cannot be attached to a meter.');
    }

    public static function usageWithoutMeter(): self
    {
        return new self('A usage-based price needs a meter to read its usage from.');
    }

    public static function unreadableAmount(string $amount, string $currency): self
    {
        return new self(sprintf('"%s" is not an amount of %s: a number with at most its minor-unit places.', $amount, $currency));
    }
}
