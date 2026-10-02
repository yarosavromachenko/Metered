<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money as BrickMoney;
use Metered\Shared\Domain\Exception\CurrencyMismatch;
use Metered\Shared\Domain\Exception\InvalidMoney;
use Stringable;

/**
 * Integer minor units plus an ISO 4217 currency. Amounts in different
 * currencies cannot be combined: a project has one currency and nothing is
 * converted.
 */
final readonly class Money implements Stringable
{
    private function __construct(private BrickMoney $amount) {}

    public function __toString(): string
    {
        return sprintf('%s %s', $this->amount->getAmount(), $this->currency());
    }

    public static function ofMinorUnits(int $minorUnits, string $currency): self
    {
        try {
            return new self(BrickMoney::ofMinor($minorUnits, strtoupper(trim($currency))));
        } catch (UnknownCurrencyException) {
            throw InvalidMoney::unknownCurrency($currency);
        }
    }

    public static function zero(string $currency): self
    {
        return self::ofMinorUnits(0, $currency);
    }

    public function minorUnits(): int
    {
        return $this->amount->getMinorAmount()->toInt();
    }

    public function currency(): string
    {
        return $this->amount->getCurrency()->getCurrencyCode();
    }

    public function plus(self $other): self
    {
        $this->guardSameCurrency($other);

        return new self($this->amount->plus($other->amount));
    }

    public function minus(self $other): self
    {
        $this->guardSameCurrency($other);

        return new self($this->amount->minus($other->amount));
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    /**
     * @return int negative, zero or positive, as with the spaceship operator
     */
    public function compareTo(self $other): int
    {
        $this->guardSameCurrency($other);

        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency() === $other->currency()
            && $this->minorUnits() === $other->minorUnits();
    }

    /**
     * Rounds half up to the minor unit. The only place a decimal becomes money;
     * a graduated charge is summed across tiers first and rounded once (ADR-0007).
     */
    public static function rounded(BigDecimal $amount, string $currency): self
    {
        try {
            return new self(BrickMoney::of($amount, strtoupper(trim($currency)), roundingMode: RoundingMode::HalfUp));
        } catch (UnknownCurrencyException) {
            throw InvalidMoney::unknownCurrency($currency);
        }
    }

    private function guardSameCurrency(self $other): void
    {
        if ($this->currency() !== $other->currency()) {
            throw CurrencyMismatch::between($this->currency(), $other->currency());
        }
    }
}
