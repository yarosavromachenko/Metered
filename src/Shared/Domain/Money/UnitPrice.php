<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Money;

use Brick\Math\BigDecimal;
use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Metered\Shared\Domain\Decimal\Decimals;
use Metered\Shared\Domain\Exception\InvalidMoney;
use Metered\Shared\Domain\Quantity\Quantity;
use Stringable;

/**
 * Price of one unit. Eight decimal places, since unit prices are often
 * fractions of a cent (€0.00012 per API call). Rounded per invoice line, so the
 * printed lines add up to the printed total.
 */
final readonly class UnitPrice implements Stringable
{
    public const int SCALE = 8;

    private function __construct(private BigDecimal $amount, private string $currency) {}

    public function __toString(): string
    {
        return sprintf('%s %s', $this->amount, $this->currency);
    }

    public static function fromString(string $amount, string $currency): self
    {
        $code = strtoupper(trim($currency));

        try {
            Currency::of($code);
        } catch (UnknownCurrencyException) {
            throw InvalidMoney::unknownCurrency($currency);
        }

        return new self(Decimals::nonNegative($amount, self::SCALE), $code);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function multipliedBy(Quantity $quantity): Money
    {
        return Money::rounded($this->amount->multipliedBy($quantity->toBigDecimal()), $this->currency);
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }
}
