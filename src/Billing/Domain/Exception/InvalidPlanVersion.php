<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class InvalidPlanVersion extends DomainException
{
    public static function numberBelowOne(int $number): self
    {
        return new self(sprintf('Plan versions are numbered from 1, %d given.', $number));
    }

    public static function currencyMismatch(string $version, string $price): self
    {
        return new self(sprintf('This version is priced in %s; a %s price cannot be added to it.', $version, $price));
    }

    public static function meterAlreadyPriced(string $meterId): self
    {
        return new self(sprintf(
            'This version already has a price on meter %s; usage would be charged twice.',
            $meterId,
        ));
    }

    public static function priceAlreadyHeld(string $priceId): self
    {
        return new self(sprintf('This version already holds price %s.', $priceId));
    }

    public static function noSuchPrice(string $priceId): self
    {
        return new self(sprintf('This version holds no price %s.', $priceId));
    }

    public static function nothingToCharge(): self
    {
        return new self('A version cannot be published without a single price.');
    }
}
