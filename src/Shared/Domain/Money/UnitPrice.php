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
 * The price of one unit, in a currency.
 *
 * Eight decimal places, because per-unit prices are routinely fractions of a
 * cent — a thousand API calls at €0.00012 each is an ordinary line on an
 * invoice, and rounding the price before multiplying would lose most of it.
 *
 * This type is where rounding happens, exactly once, when a price meets a
 * quantity and becomes money. Rounding each tier separately would give a
 * different total; rounding only at the invoice level would make the printed
 * lines fail to add up to the printed total. Rounding at the line keeps the
 * document internally consistent, which is the property an accountant checks.
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
            // Validated against the same currency table that will later decide
            // how many minor units the rounded total has.
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
