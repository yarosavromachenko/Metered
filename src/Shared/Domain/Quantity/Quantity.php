<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Quantity;

use Brick\Math\BigDecimal;
use Metered\Shared\Domain\Decimal\Decimals;
use Stringable;

/**
 * Consumed amount, six decimal places like its column (GB-hours, fractional
 * credits). Never negative: usage is taken back with a credit note.
 */
final readonly class Quantity implements Stringable
{
    public const int SCALE = 6;

    private function __construct(private BigDecimal $value) {}

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public static function fromString(string $value): self
    {
        return new self(Decimals::nonNegative($value, self::SCALE));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE));
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function max(self $other): self
    {
        return $this->value->isGreaterThan($other->value) ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    /**
     * @return int negative, zero or positive, as with the spaceship operator
     */
    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function equals(self $other): bool
    {
        return $this->value->isEqualTo($other->value);
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }
}
