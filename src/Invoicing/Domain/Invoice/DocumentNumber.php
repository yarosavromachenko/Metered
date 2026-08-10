<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Metered\Invoicing\Domain\Exception\InvalidInvoice;
use Stringable;

/**
 * The number printed on a finalized invoice or a credit note.
 *
 * Each kind counts on its own, per organization, from one and without gaps
 * (ADR-0010). The sequence is what is stored and compared; the prefix and the
 * padding are how it is printed.
 */
final readonly class DocumentNumber implements Stringable
{
    private function __construct(
        public string $prefix,
        public int $sequence,
    ) {}

    public function __toString(): string
    {
        return sprintf('%s-%06d', $this->prefix, $this->sequence);
    }

    public static function invoice(int $sequence): self
    {
        return self::of('INV', $sequence);
    }

    public static function creditNote(int $sequence): self
    {
        return self::of('CN', $sequence);
    }

    public function equals(self $other): bool
    {
        return $this->prefix === $other->prefix && $this->sequence === $other->sequence;
    }

    private static function of(string $prefix, int $sequence): self
    {
        if ($sequence < 1) {
            throw InvalidInvoice::numberNotPositive($sequence);
        }

        return new self($prefix, $sequence);
    }
}
