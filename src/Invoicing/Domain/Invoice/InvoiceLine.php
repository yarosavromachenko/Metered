<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Metered\Invoicing\Domain\Exception\InvalidInvoice;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * One line of an invoice: an amount, the period it bills, and how it was
 * arrived at.
 *
 * Every line carries its calculation — the tiers, the unit price, the
 * quantity — so that the figure can be checked on paper by someone who does
 * not trust the system. A line that only says "€41.30" invites a dispute.
 */
final readonly class InvoiceLine
{
    /**
     * @param list<string> $calculation
     */
    private function __construct(
        public LineKind $kind,
        public string $description,
        public Money $amount,
        public InvoicePeriod $covers,
        public Uuid $priceId,
        public ?Uuid $meterId,
        public ?string $meterCode,
        public ?Quantity $quantity,
        public array $calculation,
    ) {
        if ($amount->isNegative()) {
            throw InvalidInvoice::negativeLine((string) $amount);
        }
    }

    /**
     * @param list<string> $calculation
     */
    public static function fixed(Uuid $priceId, string $description, Money $amount, InvoicePeriod $covers, array $calculation): self
    {
        return new self(LineKind::Fixed, $description, $amount, $covers, $priceId, null, null, null, $calculation);
    }

    public static function usage(MeterCharge $charge, InvoicePeriod $covers): self
    {
        return self::metered(LineKind::Usage, sprintf('Usage of %s', $charge->meterCode), $charge, $covers);
    }

    /**
     * @param list<string> $calculation
     *
     * @internal for the repository, rebuilding a line exactly as it was stored
     */
    public static function restore(
        LineKind $kind,
        string $description,
        Money $amount,
        InvoicePeriod $covers,
        Uuid $priceId,
        ?Uuid $meterId,
        ?string $meterCode,
        ?Quantity $quantity,
        array $calculation,
    ): self {
        return new self($kind, $description, $amount, $covers, $priceId, $meterId, $meterCode, $quantity, $calculation);
    }

    /**
     * Usage that reached $covers after its invoice was built: the quantity is
     * the part not yet billed, the amount what it adds to the period's charge.
     */
    public static function late(MeterCharge $charge, InvoicePeriod $covers): self
    {
        return self::metered(
            LineKind::Late,
            sprintf('Late usage of %s for %s', $charge->meterCode, $covers),
            $charge,
            $covers,
        );
    }

    private static function metered(LineKind $kind, string $description, MeterCharge $charge, InvoicePeriod $covers): self
    {
        return new self(
            $kind,
            $description,
            $charge->amount,
            $covers,
            $charge->priceId,
            $charge->meterId,
            $charge->meterCode,
            $charge->quantity,
            $charge->calculation,
        );
    }
}
